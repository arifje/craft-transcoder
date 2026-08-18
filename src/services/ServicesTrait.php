<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2022 nystudio107
 */

namespace nystudio107\transcoder\services;

use nystudio107\pluginvite\services\VitePluginService;
use nystudio107\transcoder\assetbundles\transcoder\TranscoderAsset;
use yii\base\InvalidConfigException;

/**
 * @author    nystudio107
 * @package   Transcode
 * @since     1.2.23
 *
 * @property EncodingConcurrencyService $encodingConcurrency
 * @property RuntimeSettingsService $runtimeSettings
 * @property Transcode $transcode
 * @property VitePluginService $vite
 */
trait ServicesTrait
{
    // Public Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'encodingConcurrency' => EncodingConcurrencyService::class,
                'runtimeSettings' => RuntimeSettingsService::class,
                'transcode' => Transcode::class,
                // Register the vite service
                'vite' => [
                    'class' => VitePluginService::class,
                    'assetClass' => TranscoderAsset::class,
                    'useDevServer' => true,
                    'devServerPublic' => 'http://localhost:3001',
                    'serverPublic' => 'http://localhost:8000',
                    'errorEntry' => 'src/js/app.ts',
                    'devServerInternal' => 'http://craft-transcoder-buildchain:3001',
                    'checkDevServer' => true,
                ],
            ]
        ];
    }

    // Public Methods
    // =========================================================================

    /**
     * Returns the transcode service
     *
     * @return Transcode The transcode service
     * @throws InvalidConfigException
     */
    public function getTranscode(): Transcode
    {
        return $this->get('transcode');
    }

    /**
     * Returns the FFmpeg concurrency service.
     */
    public function getEncodingConcurrency(): EncodingConcurrencyService
    {
        return $this->get('encodingConcurrency');
    }

    /**
     * Returns the runtime settings service
     *
     * @return RuntimeSettingsService The runtime settings service
     * @throws InvalidConfigException
     */
    public function getRuntimeSettings(): RuntimeSettingsService
    {
        return $this->get('runtimeSettings');
    }

    /**
     * Returns the vite service
     *
     * @return VitePluginService The vite service
     * @throws InvalidConfigException
     */
    public function getVite(): VitePluginService
    {
        return $this->get('vite');
    }
}
