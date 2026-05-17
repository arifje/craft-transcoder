<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use nystudio107\transcoder\Transcoder;
use Throwable;

/**
 * Queue job that starts a video encode and records queue-aware status metadata.
 */
class EncodeVideo extends BaseJob
{
    /**
     * @var int|null
     */
    public ?int $assetId = null;

    /**
     * @var array
     */
    public array $videoOptions = [];

    /**
     * @var array
     */
    public array $encodingOptions = [];

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $asset = Asset::find()->id($this->assetId)->one();

        if (!$asset instanceof Asset) {
            Craft::error('Transcoder queue job could not find asset ID: ' . $this->assetId, __METHOD__);
            return;
        }

        try {
            $settings = Transcoder::$plugin->getSettings();

            if ($settings->enableVideoEncoding) {
                Transcoder::$plugin->transcode->writeVideoStatus(
                    $asset,
                    $this->videoOptions,
                    [
                        'status' => 'encoding',
                        'url' => '',
                        'progress' => 0,
                        'info' => 'Encoding started',
                    ],
                    $this->encodingOptions
                );

                $response = Transcoder::$plugin->transcode->getVideoUrl($asset, $this->videoOptions, true);
                $status = json_decode($response, true);

                if (is_array($status)) {
                    Transcoder::$plugin->transcode->writeVideoStatus(
                        $asset,
                        $this->videoOptions,
                        $status,
                        $this->encodingOptions
                    );
                }
            }

            if ($settings->enableVideoPosters) {
                Transcoder::$plugin->transcode->generateVideoPosters($asset);
            }
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);
            if (Transcoder::$plugin->getSettings()->enableVideoEncoding) {
                Transcoder::$plugin->transcode->writeVideoStatus(
                    $asset,
                    $this->videoOptions,
                    [
                        'status' => 'error',
                        'url' => '',
                        'error' => $e->getMessage(),
                    ],
                    $this->encodingOptions
                );
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return 'Encoding video asset #' . ($this->assetId ?? 'unknown');
    }
}
