<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\ModelEvent;
use craft\events\PluginEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\helpers\App;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\services\Assets;
use craft\services\Plugins;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use nystudio107\transcoder\gql\TranscoderGql;
use nystudio107\transcoder\jobs\InspectMediaAsset;
use nystudio107\transcoder\models\Settings;
use nystudio107\transcoder\services\AssetEditor;
use nystudio107\transcoder\services\ServicesTrait;
use nystudio107\transcoder\utilities\EncodingUtility;
use nystudio107\transcoder\variables\TranscoderVariable;
use Throwable;
use yii\base\ErrorException;
use yii\base\Event;

/**
 * Class Transcoder
 *
 * @author    nystudio107
 * @package   Transcode
 * @since     1.0.0
 */
class Transcoder extends Plugin
{
    // Traits
    // =========================================================================

    use ServicesTrait;

    // Const Properties
    // =========================================================================

    /**
     * Settings that are concatenated into ffmpeg/ffprobe shell commands or
     * control filesystem locations. They can only be set via
     * config/transcoder.php (or a console request), never via a CP POST.
     *
     * @var string[]
     */
    public const CONFIG_ONLY_SETTINGS = [
        'audioEncoders',
        'autoEncodeEncodingOptions',
        'autoEncodeGifOptions',
        'autoEncodeVideoOptions',
        'defaultAudioOptions',
        'defaultGifOptions',
        'defaultThumbnailOptions',
        'defaultVideoOptions',
        'ffmpegPath',
        'ffprobeOptions',
        'ffprobePath',
        'transcoderPaths',
        'transcoderUrls',
        'videoEncoders',
    ];

    // Static Properties
    // =========================================================================

    /**
     * @var null|Transcoder
     */
    public static ?Transcoder $plugin;

    /**
     * @var null|Settings
     */
    public static ?Settings $settings;

    // Public Properties
    // =========================================================================

    /**
     * @var bool
     */
    public bool $hasCpSection = false;

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * Bumped from upstream 1.2.0 so existing installs run
     * m260519_100000_create_runtime_settings_table (the CP Utility kill switch).
     *
     * @var string
     */
    public string $schemaVersion = '1.3.0';

    // Protected Properties
    // =========================================================================

    /**
     * Asset IDs that already received an inspection job in this request.
     *
     * @var array<int, bool>
     */
    protected array $queuedAssetInspectionJobs = [];

    // Private Properties
    // =========================================================================

    /**
     * Config-only setting values as loaded at boot (project config merged
     * with config/transcoder.php), restored before a web settings save.
     *
     * @var array<string, mixed>
     */
    private array $_configOnlySettingValues = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;
        // Initialize properties
        self::$settings = self::$plugin->getSettings();
        $this->_configOnlySettingValues = $this->_getConfigOnlySettingValues();
        // Handle console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'nystudio107\transcoder\console\controllers';
        }
        // Add in our Craft components
        $this->addComponents();
        // Register GraphQL fields
        TranscoderGql::register();
        // Install our global event handlers
        $this->installEventHandlers();
        // Register settings page tabs
        $this->registerSettingsTabs();
        // Register CP utilities
        $this->registerUtilities();
        AssetEditor::register();
        // We've loaded!
        Craft::info(
            Craft::t(
                'transcoder',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    /**
     * Clear all the caches!
     */
    public function clearAllCaches(): void
    {
        $transcoderPaths = self::$plugin->getSettings()->transcoderPaths;

        foreach ($transcoderPaths as $key => $value) {
            $dir = App::parseEnv($value);
            try {
                FileHelper::clearDirectory($dir);
                Craft::info(
                    Craft::t(
                        'transcoder',
                        '{name} cache directory cleared',
                        ['name' => $key]
                    ),
                    __METHOD__
                );
            } catch (ErrorException $e) {
                // the directory doesn't exist
                Craft::error($e->getMessage(), __METHOD__);
            }
        }
    }

    /**
     * Return the plugin's configured settings model.
     *
     * @return Settings
     */
    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        return $settings;
    }

    /**
     * Restore config-only (shell- and filesystem-bound) settings before a web
     * request persists plugin settings, so a crafted CP POST cannot change the
     * ffmpeg/ffprobe binaries, encoder option strings, or output paths.
     *
     * @inheritdoc
     */
    public function beforeSaveSettings(): bool
    {
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $settings = $this->getSettings();
            foreach ($this->_configOnlySettingValues as $name => $value) {
                $settings->$name = $value;
            }
        }

        return parent::beforeSaveSettings();
    }

    /**
     * @inheritdoc
     */
    public function afterSaveSettings(): void
    {
        self::$settings = $this->getSettings();

        parent::afterSaveSettings();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        try {
            return Craft::$app->getView()->renderTemplate(
                'transcoder/_settings',
                [
                    'settings' => $this->getSettings(),
                    'plugin' => $this,
                ]
            );
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Add in our Craft components
     */
    protected function addComponents(): void
    {
        // Register our variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('transcoder', [
                    'class' => TranscoderVariable::class,
                    'viteService' => $this->vite,
                ]);
            }
        );
    }

    /**
     * Register Craft CP tabs for the plugin settings page.
     */
    protected function registerSettingsTabs(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            function(TemplateEvent $event) {
                if (
                    $event->template === 'settings/plugins/_settings.twig'
                    && ($event->variables['plugin']->handle ?? null) === $this->handle
                ) {
                    $event->variables['tabs'] = [
                        [
                            'label' => Craft::t('transcoder', 'Video'),
                            'url' => '#settings-tab-video',
                        ],
                        [
                            'label' => Craft::t('transcoder', 'Watermark'),
                            'url' => '#settings-tab-watermark',
                        ],
                        [
                            'label' => Craft::t('transcoder', 'Video posters'),
                            'url' => '#settings-tab-video-posters',
                        ],
                        [
                            'label' => Craft::t('transcoder', 'GIF'),
                            'url' => '#settings-tab-gif',
                        ],
                    ];
                }
            }
        );
    }

    /**
     * Register CP utilities.
     */
    protected function registerUtilities(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = EncodingUtility::class;
            }
        );
    }

    /**
     * Install our event handlers
     */
    protected function installEventHandlers(): void
    {
        $settings = $this->getSettings();
        // Handler: Assets::EVENT_GET_THUMB_PATH
        Event::on(
            Assets::class,
            Assets::EVENT_DEFINE_THUMB_URL,
            static function(DefineAssetThumbUrlEvent $event) {
                Craft::debug(
                    'Assets::EVENT_GET_THUMB_PATH',
                    __METHOD__
                );
                $asset = $event->asset;
                if (AssetsHelper::getFileKindByExtension($asset->filename) === Asset::KIND_VIDEO) {
                    $path = Transcoder::$plugin->transcode->handleGetAssetThumbPath($event);
                    if (!empty($path)) {
                        $event->url = $path;
                    }
                }
            }
        );
        if ($settings->clearCaches) {
            // Add the Transcoded path to the list of things the Clear Caches tool can delete.
            Event::on(
                ClearCaches::class,
                ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
                function(RegisterCacheOptionsEvent $event) {
                    $event->options[] = [
                        'key' => 'transcoder',
                        'label' => Craft::t('transcoder', 'Transcoder caches'),
                        'action' => [$this, 'clearAllCaches'],
                    ];
                }
            );
        }
        Event::on(
            Asset::class,
            Asset::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                $this->handleAssetAfterSave($event);
            }
        );
        // Handler: Plugins::EVENT_AFTER_INSTALL_PLUGIN
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function(PluginEvent $event) {
                if ($event->plugin === $this) {
                    $request = Craft::$app->getRequest();
                    if ($request->getIsCpRequest()) {
                        Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('transcoder/welcome'))->send();
                    }
                }
            }
        );
        // Handler: UrlManager::EVENT_REGISTER_CP_URL_RULES
        // The welcome template is underscore-prefixed (not directly routable),
        // so expose it through an explicit CP route.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['transcoder/welcome'] = ['template' => 'transcoder/_welcome'];
            }
        );
    }

    /**
     * Queue inspection after a new Asset upload, excluding Craft bulk resaves.
     */
    protected function handleAssetAfterSave(ModelEvent $event): void
    {
        $asset = $event->sender;
        if (!$asset instanceof Asset || $asset->resaving) {
            return;
        }

        if (!$event->isNew) {
            return;
        }

        $this->queueMediaInspectionForUploadedAsset($asset);
    }

    /**
     * Queue asynchronous inspection for a newly uploaded media asset.
     *
     * @param Asset $asset
     * @return void
     */
    protected function queueMediaInspectionForUploadedAsset(Asset $asset): void
    {
        if (!$asset->id) {
            return;
        }

        if (!$this->transcode->isQueueableMediaAsset($asset)) {
            return;
        }

        if (isset($this->queuedAssetInspectionJobs[$asset->id])) {
            return;
        }

        $this->queuedAssetInspectionJobs[$asset->id] = true;
        $settings = $this->getSettings();
        $jobId = Craft::$app->getQueue()->push(new InspectMediaAsset([
            'assetId' => $asset->id,
            'attempt' => 1,
            'maxRetries' => max(0, (int)$settings->mediaInspectionMaxRetries),
            'retryDelaySeconds' => max(0, (int)$settings->mediaInspectionRetryDelaySeconds),
        ]));

        Craft::info('Transcoder: queued media inspection job ' . $jobId . ' for new asset #' . $asset->id, __METHOD__);
    }

    // Private Methods
    // =========================================================================

    /**
     * Return the current values of the config-only settings.
     *
     * @return array<string, mixed>
     */
    private function _getConfigOnlySettingValues(): array
    {
        $settings = $this->getSettings();
        $values = [];
        foreach (self::CONFIG_ONLY_SETTINGS as $name) {
            $values[$name] = $settings->$name;
        }

        return $values;
    }
}
