<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\models;

use Craft;
use craft\base\Model;
use craft\validators\ArrayValidator;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Transcoder Settings model
 *
 * @author    nystudio107
 * @package   Transcode
 * @since     1.0.0
 */
class Settings extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * Characters that must never appear in values concatenated into shell
     * commands: ; | & ` $ < > and line breaks.
     */
    public const SHELL_METACHARACTER_PATTERN = '/[;|&`$<>\r\n]/';

    /**
     * Allowed characters for the ffmpeg/ffprobe binary paths.
     */
    public const BINARY_PATH_PATTERN = '/^[A-Za-z0-9_.\/\-]+$/';

    /**
     * Keys every video encoder preset must define.
     */
    public const VIDEO_ENCODER_KEYS = ['fileSuffix', 'fileFormat', 'videoCodec', 'videoCodecOptions', 'threads'];

    /**
     * Keys every audio encoder preset must define.
     */
    public const AUDIO_ENCODER_KEYS = ['fileSuffix', 'fileFormat', 'audioCodec', 'audioCodecOptions', 'threads'];

    /**
     * Valid watermark positions (mirrors Transcode::WATERMARK_POSITIONS).
     */
    public const WATERMARK_POSITIONS = [
        'top-left',
        'top-center',
        'top-right',
        'center-left',
        'center',
        'center-right',
        'bottom-left',
        'bottom-center',
        'bottom-right',
    ];

    // Public Properties
    // =========================================================================

    /**
     * The path to the ffmpeg binary
     *
     * @var string
     */
    public string $ffmpegPath = '/usr/bin/ffmpeg';

    /**
     * The path to the ffprobe binary
     *
     * @var string
     */
    public string $ffprobePath = '/usr/bin/ffprobe';

    /**
     * The options to use for ffprobe
     *
     * @var string
     */
    public string $ffprobeOptions = '-v quiet -print_format json -show_format -show_streams';

    /**
     * The path where the transcoded videos are stored; must have a trailing /
     * Yii2 aliases are supported here
     *
     * @var array
     */
    public array $transcoderPaths = [
        'default' => '@webroot/transcoder/',
        'video' => '@webroot/transcoder/',
        'audio' => '@webroot/transcoder/',
        'thumbnail' => '@webroot/transcoder/',
        'gif' => '@webroot/transcoder/',
    ];

    /**
     * The URL where the transcoded videos are stored; must have a trailing /
     * Yii2 aliases are supported here
     *
     * @var array
     */
    public array $transcoderUrls = [
        'default' => '@web/transcoder/',
        'video' => '@web/transcoder/',
        'audio' => '@web/transcoder/',
        'thumbnail' => '@web/transcoder/',
        'gif' => '@web/transcoder/',
    ];

    /**
     * @var bool Determines whether the download file endpoint should be enabled for anonymous frontend access
     */
    public bool $enableDownloadFileEndpoint = false;

    /**
     * @var bool Determines whether video encoding should be enabled
     */
    public bool $enableVideoEncoding = true;

    /**
     * @var bool Detect and crop black bars from uploaded videos before encoding
     */
    public bool $autoCropVideoBlackBars = false;

    /**
     * @var bool Determines whether encoded videos should receive a watermark
     */
    public bool $enableVideoWatermark = false;

    /**
     * @var int|string|array|null Legacy selected Craft asset ID fallback for the video watermark image
     */
    public int|string|array|null $videoWatermarkAsset = null;

    /**
     * @var string|bool|null Optional local path/alias used before the URL and legacy asset fallback
     */
    public string|bool|null $videoWatermarkPath = '';

    /**
     * @var string|bool|null Optional remote URL used before the legacy asset fallback
     */
    public string|bool|null $videoWatermarkUrl = '';

    /**
     * @var int|string Watermark width at 720px video width, or empty for original width at that baseline
     */
    public int|string $videoWatermarkWidth = '';

    /**
     * @var int|string Watermark height at 720px video width, or empty for original height at that baseline
     */
    public int|string $videoWatermarkHeight = '';

    /**
     * @var string Watermark placement on the encoded video
     */
    public string $videoWatermarkPosition = 'bottom-right';

    /**
     * @var int Watermark top padding in pixels
     */
    public int $videoWatermarkPaddingTop = 24;

    /**
     * @var int Watermark right padding in pixels
     */
    public int $videoWatermarkPaddingRight = 24;

    /**
     * @var int Watermark bottom padding in pixels
     */
    public int $videoWatermarkPaddingBottom = 24;

    /**
     * @var int Watermark left padding in pixels
     */
    public int $videoWatermarkPaddingLeft = 24;

    /**
     * @var int Watermark opacity percentage
     */
    public int $videoWatermarkOpacity = 100;

    /**
     * @var string Watermark animation style
     */
    public string $videoWatermarkAnimation = 'none';

    /**
     * @var bool Move the watermark between positions during the video
     */
    public bool $videoWatermarkReposition = false;

    /**
     * @var int Seconds between watermark position changes
     */
    public int $videoWatermarkRepositionInterval = 10;

    /**
     * @var array Positions to cycle through when watermark repositioning is enabled
     */
    public array $videoWatermarkRepositionPositions = [
        'top-left',
        'top-right',
        'bottom-right',
        'bottom-left',
    ];

    /**
     * @var bool Determines whether video poster generation should be enabled
     */
    public bool $enableVideoPosters = true;

    /**
     * @var bool Adds a blurred cover background behind fitted video posters to avoid black bars
     */
    public bool $preventVideoPosterBlackBars = false;

    /**
     * @var bool Determines whether GIF encoding should be enabled
     */
    public bool $enableGifEncoding = true;

    /**
     * @var array|string Server names allowed to start encode work from web requests.
     */
    public array|string $encodingServerNames = [];

    /**
     * Use a md5 hash for the filenames instead of parameterized naming
     *
     * @var bool
     */
    public bool $useHashedNames = false;

    /**
     * How encoded video filenames should be generated: source or options.
     *
     * @var string
     */
    public string $videoFilenameStrategy = 'source';

    /**
     * if a upload location has a subfolder defined, add this to the transcoder
     * paths too
     *
     * @var bool
     */
    public bool $createSubfolders = true;

    /**
     * 1-based URL path segment to use as the output subfolder when a URL
     * (instead of an Asset) is passed in; false disables it
     *
     * @var int|bool
     */
    public int|bool $subfolderUrlSegment = false;

    /**
     * clear caches when somebody clears all caches from the CP?
     *
     * @var bool
     */
    public bool $clearCaches = false;

    /**
     * Queue video encoding when new video assets are uploaded outside a Craft
     * bulk resave or upgrade operation.
     *
     * @var bool
     */
    public bool $queueVideosOnSave = false;

    /**
     * Deprecated alias for queueVideosOnSave.
     *
     * This alias only enables new asset upload processing; Entry saves are not
     * inspected automatically.
     *
     * @var bool
     */
    public bool $queueVideosOnEntrySave = false;

    /**
     * Number of retries after the initial asynchronous asset inspection.
     *
     * @var int
     */
    public int $mediaInspectionMaxRetries = 5;

    /**
     * Seconds to wait before retrying asynchronous source inspection.
     *
     * @var int
     */
    public int $mediaInspectionRetryDelaySeconds = 10;

    /**
     * Seconds Craft should reserve for video-related queue jobs before timing them out.
     *
     * @var int
     */
    public int $videoQueueTtrSeconds = 1800;

    /**
     * Seconds to delay video encode jobs queued after asynchronous upload inspection.
     *
     * @var int
     */
    public int $videoQueueDelaySeconds = 10;

    /**
     * Maximum active video/poster FFmpeg jobs on each encoding server.
     *
     * @var int
     */
    public int $videoMaxConcurrentJobs = 1;

    /**
     * Seconds before a capacity-limited queue job tries again.
     *
     * @var int
     */
    public int $encodingConcurrencyRetryDelaySeconds = 15;

    /**
     * Maximum elapsed seconds waiting for an FFmpeg slot before failing visibly.
     */
    public int $encodingConcurrencyMaxWaitSeconds = 3600;

    /**
     * Number of retries after the initial video encode attempt fails.
     *
     * @var int
     */
    public int $videoEncodeMaxRetries = 2;

    /**
     * Seconds to wait before retrying a failed video encode job.
     *
     * @var int
     */
    public int $videoEncodeRetryDelaySeconds = 120;

    /**
     * Entry field handles used by explicit element-scanning API calls. Leave
     * empty to inspect all custom fields recursively when those methods are
     * called manually. Entry saves never invoke them automatically.
     *
     * @var array
     */
    public array $autoEncodeVideoFieldHandles = [];

    /**
     * Default options used when an asset upload queues video encoding.
     *
     * @var array
     */
    public array $autoEncodeVideoOptions = [];

    /**
     * Extra options recorded with queued encodes. These are included in the
     * status key so future output-affecting options can be distinguished.
     *
     * @var array
     */
    public array $autoEncodeEncodingOptions = [];

    /**
     * Queue GIF encoding when new GIF assets are uploaded outside a Craft bulk
     * resave or upgrade operation.
     *
     * @var bool
     */
    public bool $queueGifsOnSave = false;

    /**
     * Deprecated alias for queueGifsOnSave.
     *
     * This alias only enables new asset upload processing; Entry saves are not
     * inspected automatically.
     *
     * @var bool
     */
    public bool $queueGifsOnEntrySave = false;

    /**
     * Entry field handles used by explicit element-scanning API calls. Leave
     * empty to inspect all custom fields recursively when those methods are
     * called manually. Entry saves never invoke them automatically.
     *
     * @var array
     */
    public array $autoEncodeGifFieldHandles = [];

    /**
     * Default options used when an asset upload queues GIF encoding.
     *
     * @var array
     */
    public array $autoEncodeGifOptions = [];

    /**
     * Seconds to delay each queued GIF job after the previous one.
     *
     * @var int
     */
    public int $gifQueueDelaySeconds = 15;

    /**
     * Maximum active GIF FFmpeg jobs on each encoding server.
     *
     * @var int
     */
    public int $gifMaxConcurrentJobs = 4;

    /**
     * Number of retries after the initial video poster generation attempt fails.
     *
     * @var int
     */
    public int $videoPosterMaxRetries = 2;

    /**
     * Seconds to wait before retrying a failed video poster generation job.
     *
     * @var int
     */
    public int $videoPosterRetryDelaySeconds = 120;

    /**
     * Seconds to wait before starting newly queued video poster generation jobs.
     *
     * @var int
     */
    public int $videoPosterQueueDelaySeconds = 5;

    /**
     * Poster formats generated when videos are queued.
     *
     * @var array
     */
    public array $videoPosterFormats = [
        '16_9' => [
            'width' => 800,
            'height' => 450,
            'timeInSecs' => 3,
        ],
        'original_3s' => [
            'timeInSecs' => 3,
        ],
        'original_1s' => [
            'timeInSecs' => 1,
        ],
    ];

    /**
     * Preset video encoders
     *
     * @var array
     */
    public array $videoEncoders = [
        'h264' => [
            'fileSuffix' => '.mp4',
            'fileFormat' => 'mp4',
            'videoCodec' => 'libx264',
            'videoCodecOptions' => '-vprofile high -preset slow -crf 22',
            'audioCodec' => 'libfdk_aac',
            'audioCodecOptions' => '-async 1000',
            'threads' => '0',
        ],
        'webm' => [
            'fileSuffix' => '.webm',
            'fileFormat' => 'webm',
            'videoCodec' => 'libvpx',
            'videoCodecOptions' => '-quality good -cpu-used 0',
            'audioCodec' => 'libvorbis',
            'audioCodecOptions' => '-async 1000',
            'threads' => '0',
        ],
        'gif' => [
            'fileSuffix' => '.mp4',
            'fileFormat' => 'mp4',
            'videoCodec' => 'libx264',
            'videoCodecOptions' => '-pix_fmt yuv420p -movflags +faststart -filter:v crop=\'floor(in_w/2)*2:floor(in_h/2)*2\' ',
            'threads' => '0',
        ],
    ];

    /**
     * Preset audio encoders
     *
     * @var array
     */
    public array $audioEncoders = [
        'mp3' => [
            'fileSuffix' => '.mp3',
            'fileFormat' => 'mp3',
            'audioCodec' => 'libmp3lame',
            'audioCodecOptions' => '',
            'threads' => '0',
        ],
        'aac' => [
            'fileSuffix' => '.m4a',
            'fileFormat' => 'aac',
            'audioCodec' => 'libfdk_aac',
            'audioCodecOptions' => '',
            'threads' => '0',

        ],
        'ogg' => [
            'fileSuffix' => '.ogg',
            'fileFormat' => 'ogg',
            'audioCodec' => 'libvorbis',
            'audioCodecOptions' => '',
            'threads' => '0',
        ],
    ];

    /**
     * Default options for encoded videos
     *
     * @var array
     */
    public array $defaultVideoOptions = [
        // Video settings
        'videoEncoder' => 'h264',
        'videoBitRate' => '800k',
        'videoFrameRate' => 15,
        // Audio settings
        'audioBitRate' => '',
        'audioSampleRate' => '',
        'audioChannels' => '',
        // Spatial settings
        'width' => '',
        'height' => '',
        'sharpen' => true,
        // Can be 'none', 'crop', or 'letterbox'
        'aspectRatio' => 'letterbox',
        'letterboxColor' => '',
    ];

    /**
     * Default options for video thumbnails
     *
     * @var array
     */
    public array $defaultThumbnailOptions = [
        'fileSuffix' => '.jpg',
        'timeInSecs' => 10,
        'width' => '',
        'height' => '',
        'sharpen' => true,
        // Can be 'none', 'crop', or 'letterbox'
        'aspectRatio' => 'letterbox',
        'letterboxColor' => '',
    ];

    /**
     * Default options for encoded videos
     *
     * @var array
     */
    public array $defaultAudioOptions = [
        'audioEncoder' => 'mp3',
        'audioBitRate' => '128k',
        'audioSampleRate' => '44100',
        'audioChannels' => '2',
        'synchronous' => false,
        'stripMetadata' => false,
    ];

    /**
     * Default options for encoded GIF
     *
     * @var array
     */
    public array $defaultGifOptions = [
        'videoEncoder' => 'gif',
        'fileSuffix' => '',
        'fileFormat' => '',
        'videoCodec' => '',
        'videoCodecOptions' => '',
    ];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct(array $config = [])
    {
        // Unset any deprecated properties
        if (!empty($config)) {
            // If the old properties are set, remap them to the default
            if (isset($config['transcoderPath'])) {
                $config['transcoderPaths']['default'] = $config['transcoderPath'];
                unset($config['transcoderPath']);
            }
            if (isset($config['transcoderUrl'])) {
                $config['transcoderUrls']['default'] = $config['transcoderUrl'];
                unset($config['transcoderUrl']);
            }
        }
        parent::__construct($config);
    }

    /**
     * Craft's checkboxSelect and editableTable inputs post an empty string when
     * nothing is selected; map that to [] for array-typed settings so the
     * assignment doesn't throw a TypeError. The deprecated
     * queueVideosOnEntrySave/queueGifsOnEntrySave aliases are folded into
     * their canonical settings so the CP toggles reflect effective behavior.
     *
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        foreach ($values as $name => $value) {
            if ($value === '' && is_string($name) && $this->_isArrayTypedProperty($name)) {
                $values[$name] = [];
            }
        }

        parent::setAttributes($values, $safeOnly);

        if ($this->queueVideosOnEntrySave) {
            $this->queueVideosOnSave = true;
        }
        if ($this->queueGifsOnEntrySave) {
            $this->queueGifsOnSave = true;
        }
    }

    /**
     * Validate that a binary path contains only safe path characters.
     *
     * @param string $attribute
     */
    public function validateBinaryPath(string $attribute): void
    {
        $value = $this->$attribute;
        if (!is_string($value) || !preg_match(self::BINARY_PATH_PATTERN, $value)) {
            $this->addError($attribute, Craft::t('transcoder', '{attribute} may only contain letters, numbers, and the characters _ . / -', ['attribute' => $attribute]));
        }
    }

    /**
     * Validate that a string (or every scalar in a nested array) contains no
     * shell metacharacters.
     *
     * @param string $attribute
     */
    public function validateNoShellMetacharacters(string $attribute): void
    {
        $path = $this->_findShellMetacharacters($this->$attribute, $attribute);
        if ($path !== null) {
            $this->addError($attribute, Craft::t('transcoder', '{attribute} must not contain shell metacharacters (; | & ` $ < > or line breaks).', ['attribute' => $path]));
        }
    }

    /**
     * Validate a list of transcoder paths or URLs keyed by media type.
     *
     * @param string $attribute
     */
    public function validateMediaTypeLocations(string $attribute): void
    {
        $locations = $this->$attribute;
        if (!is_array($locations) || !isset($locations['default']) || !is_string($locations['default']) || $locations['default'] === '') {
            $this->addError($attribute, Craft::t('transcoder', '{attribute} must be an array with a non-empty “default” entry.', ['attribute' => $attribute]));
            return;
        }

        foreach ($locations as $key => $location) {
            if (!is_string($location)) {
                $this->addError($attribute, Craft::t('transcoder', '{attribute} must be a string.', ['attribute' => "$attribute.$key"]));
            }
        }
    }

    /**
     * Validate a video or audio encoder preset map.
     *
     * @param string $attribute
     */
    public function validateEncoders(string $attribute): void
    {
        $encoders = $this->$attribute;
        $requiredKeys = $attribute === 'audioEncoders' ? self::AUDIO_ENCODER_KEYS : self::VIDEO_ENCODER_KEYS;
        if (!is_array($encoders) || empty($encoders)) {
            $this->addError($attribute, Craft::t('transcoder', '{attribute} must be a non-empty array of encoder presets.', ['attribute' => $attribute]));
            return;
        }

        foreach ($encoders as $handle => $encoder) {
            if (!is_array($encoder)) {
                $this->addError($attribute, Craft::t('transcoder', '{attribute} must be an array.', ['attribute' => "$attribute.$handle"]));
                continue;
            }
            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $encoder) || !is_scalar($encoder[$key])) {
                    $this->addError($attribute, Craft::t('transcoder', '{attribute} must be set.', ['attribute' => "$attribute.$handle.$key"]));
                }
            }
        }
    }

    /**
     * Validate a default options array, including that its encoder handle
     * references a configured encoder preset.
     *
     * @param string $attribute
     * @param array|null $params
     */
    public function validateDefaultOptions(string $attribute, ?array $params): void
    {
        $options = $this->$attribute;
        if (!is_array($options)) {
            $this->addError($attribute, Craft::t('transcoder', '{attribute} must be an array.', ['attribute' => $attribute]));
            return;
        }

        $encoderKey = $params['encoderKey'] ?? null;
        $encodersAttribute = $params['encoders'] ?? null;
        if ($encoderKey === null || $encodersAttribute === null) {
            return;
        }

        $encoder = $options[$encoderKey] ?? null;
        $encoders = $this->$encodersAttribute;
        if (!is_string($encoder) || !is_array($encoders) || !isset($encoders[$encoder])) {
            $this->addError($attribute, Craft::t('transcoder', '{attribute} must name a preset defined in {encoders}.', ['attribute' => "$attribute.$encoderKey", 'encoders' => $encodersAttribute]));
        }
    }

    /**
     * Validate a value that must be an integer (>= 0) or empty.
     *
     * @param string $attribute
     */
    public function validateOptionalDimension(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === '' || $value === null) {
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            $this->addError($attribute, Craft::t('transcoder', '{attribute} must be a whole number of pixels, or empty.', ['attribute' => $attribute]));
        }
    }

    /**
     * Validate that every watermark reposition position is a known position.
     *
     * @param string $attribute
     */
    public function validateWatermarkPositions(string $attribute): void
    {
        $positions = $this->$attribute;

        if (!is_array($positions)) {
            $this->addError($attribute, Craft::t('transcoder', '{attribute} must be an array of positions.', ['attribute' => $attribute]));
            return;
        }

        foreach ($positions as $position) {
            if (!in_array($position, self::WATERMARK_POSITIONS, true)) {
                $this->addError($attribute, Craft::t('transcoder', '{attribute} contains an invalid position.', ['attribute' => $attribute]));
                return;
            }
        }
    }

    /**
     * Validate the URL segment used as an output subfolder: false or an
     * integer >= 1.
     *
     * @param string $attribute
     */
    public function validateSubfolderUrlSegment(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === false || $value === '' || $value === null || $value === '0' || $value === 0) {
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->addError($attribute, Craft::t('transcoder', '{attribute} must be false or a URL segment number (1 or higher).', ['attribute' => $attribute]));
        }
    }

    /**
     * Validate the video poster format map editable in the CP.
     *
     * @param string $attribute
     */
    public function validateVideoPosterFormats(string $attribute): void
    {
        $formats = $this->$attribute;
        if (!is_array($formats)) {
            $this->addError($attribute, Craft::t('transcoder', '{attribute} must be an array.', ['attribute' => $attribute]));
            return;
        }

        foreach ($formats as $handle => $format) {
            if (!is_array($format)) {
                $this->addError($attribute, Craft::t('transcoder', '{attribute} must be an array.', ['attribute' => "$attribute.$handle"]));
                continue;
            }
            foreach (['width', 'height', 'timeInSecs'] as $key) {
                $value = $format[$key] ?? '';
                if ($value !== '' && !is_numeric($value)) {
                    $this->addError($attribute, Craft::t('transcoder', '{attribute} must be a number or empty.', ['attribute' => "$attribute.$handle.$key"]));
                }
            }
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        return array_merge($rules, [
            [['ffmpegPath', 'ffprobePath'], 'required'],
            [['ffmpegPath', 'ffprobePath', 'ffprobeOptions'], 'string'],
            [['ffmpegPath', 'ffprobePath'], 'validateBinaryPath'],
            [[
                'ffprobeOptions',
                'videoEncoders',
                'audioEncoders',
                'defaultVideoOptions',
                'defaultThumbnailOptions',
                'defaultAudioOptions',
                'defaultGifOptions',
                'autoEncodeVideoOptions',
                'autoEncodeGifOptions',
                'autoEncodeEncodingOptions',
                'videoPosterFormats',
            ], 'validateNoShellMetacharacters', 'skipOnEmpty' => true],
            [['transcoderPaths', 'transcoderUrls'], 'required'],
            [['transcoderPaths', 'transcoderUrls'], 'validateMediaTypeLocations'],
            ['enableDownloadFileEndpoint', 'boolean'],
            ['enableVideoEncoding', 'boolean'],
            ['autoCropVideoBlackBars', 'boolean'],
            ['enableVideoWatermark', 'boolean'],
            ['videoWatermarkAsset', 'safe'],
            [['videoWatermarkPath', 'videoWatermarkUrl'], 'safe'],
            [['videoWatermarkWidth', 'videoWatermarkHeight'], 'validateOptionalDimension', 'skipOnEmpty' => false],
            ['videoWatermarkPosition', 'in', 'range' => self::WATERMARK_POSITIONS],
            [[
                'videoWatermarkPaddingTop',
                'videoWatermarkPaddingRight',
                'videoWatermarkPaddingBottom',
                'videoWatermarkPaddingLeft',
                'videoWatermarkOpacity',
                'videoWatermarkRepositionInterval',
            ], 'integer'],
            [[
                'videoWatermarkPaddingTop',
                'videoWatermarkPaddingRight',
                'videoWatermarkPaddingBottom',
                'videoWatermarkPaddingLeft',
                'videoWatermarkRepositionInterval',
            ], 'number', 'min' => 0],
            ['videoWatermarkOpacity', 'number', 'min' => 0, 'max' => 100],
            ['videoWatermarkAnimation', 'in', 'range' => [
                'none',
                'fade-in',
                'fade-out',
                'fade-in-out',
                'rotate',
                'pulse',
            ]],
            ['videoWatermarkReposition', 'boolean'],
            ['videoWatermarkRepositionPositions', 'validateWatermarkPositions', 'skipOnEmpty' => false],
            ['enableVideoPosters', 'boolean'],
            ['preventVideoPosterBlackBars', 'boolean'],
            ['enableGifEncoding', 'boolean'],
            ['encodingServerNames', 'safe'],
            ['useHashedNames', 'boolean'],
            ['videoFilenameStrategy', 'in', 'range' => ['source', 'options']],
            ['createSubfolders', 'boolean'],
            ['subfolderUrlSegment', 'validateSubfolderUrlSegment', 'skipOnEmpty' => false],
            ['clearCaches', 'boolean'],
            [['queueVideosOnSave', 'queueVideosOnEntrySave'], 'boolean'],
            [['mediaInspectionMaxRetries', 'mediaInspectionRetryDelaySeconds'], 'integer'],
            [['mediaInspectionMaxRetries', 'mediaInspectionRetryDelaySeconds'], 'number', 'min' => 0],
            [[
                'videoQueueTtrSeconds',
                'videoQueueDelaySeconds',
                'videoMaxConcurrentJobs',
                'gifMaxConcurrentJobs',
                'encodingConcurrencyRetryDelaySeconds',
                'encodingConcurrencyMaxWaitSeconds',
            ], 'integer'],
            ['videoQueueTtrSeconds', 'number', 'min' => 1],
            [[
                'videoMaxConcurrentJobs',
                'gifMaxConcurrentJobs',
                'encodingConcurrencyRetryDelaySeconds',
                'encodingConcurrencyMaxWaitSeconds',
            ], 'number', 'min' => 1],
            ['videoQueueDelaySeconds', 'number', 'min' => 0],
            [['videoEncodeMaxRetries', 'videoEncodeRetryDelaySeconds'], 'integer'],
            [['videoEncodeMaxRetries', 'videoEncodeRetryDelaySeconds'], 'number', 'min' => 0],
            ['autoEncodeVideoFieldHandles', ArrayValidator::class],
            ['autoEncodeVideoOptions', ArrayValidator::class],
            ['autoEncodeEncodingOptions', ArrayValidator::class],
            [['queueGifsOnSave', 'queueGifsOnEntrySave'], 'boolean'],
            ['autoEncodeGifFieldHandles', ArrayValidator::class],
            ['autoEncodeGifOptions', ArrayValidator::class],
            ['gifQueueDelaySeconds', 'integer'],
            ['gifQueueDelaySeconds', 'number', 'min' => 0],
            [['videoPosterMaxRetries', 'videoPosterRetryDelaySeconds', 'videoPosterQueueDelaySeconds'], 'integer'],
            [['videoPosterMaxRetries', 'videoPosterRetryDelaySeconds', 'videoPosterQueueDelaySeconds'], 'number', 'min' => 0],
            ['videoPosterFormats', 'validateVideoPosterFormats'],
            [['videoEncoders', 'audioEncoders'], 'required'],
            [['videoEncoders', 'audioEncoders'], 'validateEncoders'],
            [['defaultVideoOptions', 'defaultThumbnailOptions', 'defaultAudioOptions', 'defaultGifOptions'], 'required'],
            ['defaultVideoOptions', 'validateDefaultOptions', 'params' => ['encoderKey' => 'videoEncoder', 'encoders' => 'videoEncoders']],
            ['defaultThumbnailOptions', 'validateDefaultOptions'],
            ['defaultAudioOptions', 'validateDefaultOptions', 'params' => ['encoderKey' => 'audioEncoder', 'encoders' => 'audioEncoders']],
            ['defaultGifOptions', 'validateDefaultOptions', 'params' => ['encoderKey' => 'videoEncoder', 'encoders' => 'videoEncoders']],
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Return the dotted path of the first value containing a shell
     * metacharacter, or null if the value is clean.
     *
     * @param mixed $value
     * @param string $path
     * @return string|null
     */
    private function _findShellMetacharacters(mixed $value, string $path): ?string
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $found = $this->_findShellMetacharacters($item, "$path.$key");
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }

        if (is_string($value) && preg_match(self::SHELL_METACHARACTER_PATTERN, $value)) {
            return $path;
        }

        return null;
    }

    /**
     * Return whether a public property is declared with the `array` type.
     *
     * @param string $name
     * @return bool
     */
    private function _isArrayTypedProperty(string $name): bool
    {
        if (!property_exists($this, $name)) {
            return false;
        }

        $type = (new ReflectionProperty($this, $name))->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === 'array';
    }
}
