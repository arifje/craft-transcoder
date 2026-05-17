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
 * Queue job that starts GIF encoding and records queue-aware status metadata.
 */
class EncodeGif extends BaseJob
{
    /**
     * @var int|null
     */
    public ?int $assetId = null;

    /**
     * @var array
     */
    public array $gifOptions = [];

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $asset = Asset::find()->id($this->assetId)->one();

        if (!$asset instanceof Asset) {
            Craft::error('Transcoder queue job could not find GIF asset ID: ' . $this->assetId, __METHOD__);
            return;
        }

        try {
            Transcoder::$plugin->transcode->writeGifStatus(
                $asset,
                $this->gifOptions,
                [
                    'status' => 'encoding',
                    'url' => '',
                    'progress' => 0,
                    'info' => 'GIF encoding started',
                ]
            );

            $response = Transcoder::$plugin->transcode->getGifUrl($asset, $this->gifOptions, true);
            $status = json_decode($response, true);

            if (is_array($status)) {
                Transcoder::$plugin->transcode->writeGifStatus($asset, $this->gifOptions, $status);
            }
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);
            Transcoder::$plugin->transcode->writeGifStatus(
                $asset,
                $this->gifOptions,
                [
                    'status' => 'error',
                    'url' => '',
                    'error' => $e->getMessage(),
                ]
            );
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return 'Encoding GIF asset #' . ($this->assetId ?? 'unknown');
    }
}
