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
    private const POLL_INTERVAL_SECONDS = 2;
    private const TIMEOUT_SECONDS = 3600;

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

        if (!Transcoder::$plugin->transcode->isRuntimeEncodingEnabled()) {
            Transcoder::$plugin->transcode->writeGifStatus(
                $asset,
                $this->gifOptions,
                [
                    'status' => 'disabled',
                    'url' => '',
                    'progress' => 0,
                ]
            );
            $this->setProgress($queue, 1, Craft::t('transcoder', 'Encoding disabled'));
            return;
        }

        try {
            $this->setProgress($queue, 0, Craft::t('transcoder', 'Starting GIF encode'));
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

            $this->waitForGifEncode($queue, $asset, is_array($status) ? $status : []);
            $this->setProgress($queue, 1, Craft::t('transcoder', 'GIF encode complete'));
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);
            $status = [
                'status' => 'error',
                'url' => '',
                'error' => $e->getMessage(),
            ];
            try {
                $currentStatus = Transcoder::$plugin->transcode->getGifStatusData($asset, $this->gifOptions);
                if (($currentStatus['status'] ?? null) === 'error') {
                    $status = array_merge($currentStatus, $status);
                }
            } catch (Throwable) {
            }

            Transcoder::$plugin->transcode->writeGifStatus(
                $asset,
                $this->gifOptions,
                $status
            );

            throw $e;
        }
    }

    /**
     * Keep the queue job alive while the background ffmpeg process runs.
     *
     * @param mixed $queue
     * @param Asset $asset
     * @param array $initialStatus
     * @return void
     */
    protected function waitForGifEncode(mixed $queue, Asset $asset, array $initialStatus): void
    {
        if (($initialStatus['status'] ?? null) === 'ok') {
            $this->setProgress($queue, 1, Craft::t('transcoder', 'GIF encode complete'));
            return;
        }

        if (($initialStatus['status'] ?? null) === 'error') {
            throw new \RuntimeException($this->formatErrorMessage($initialStatus));
        }

        $deadline = time() + self::TIMEOUT_SECONDS;
        while (time() < $deadline) {
            $status = Transcoder::$plugin->transcode->getGifStatusData($asset, $this->gifOptions);
            $state = $status['status'] ?? 'unknown';
            $rawProgress = $status['progress'] ?? 0;
            $progress = is_numeric($rawProgress) ? (int)$rawProgress : 0;

            if ($state === 'ok') {
                $this->setProgress($queue, 1, Craft::t('transcoder', 'GIF encode complete'));
                return;
            }

            if ($state === 'error') {
                throw new \RuntimeException($this->formatErrorMessage($status));
            }

            Transcoder::$plugin->transcode->writeGifStatus($asset, $this->gifOptions, $status);
            $this->setProgress(
                $queue,
                min(0.98, max(0, $progress / 100)),
                Craft::t('transcoder', 'Encoding GIF: {progress}%', ['progress' => $progress])
            );

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        throw new \RuntimeException(Craft::t('transcoder', 'GIF encoding timed out'));
    }

    /**
     * Format a queue-visible error message with ffmpeg debug details.
     *
     * @param array $status
     * @return string
     */
    protected function formatErrorMessage(array $status): string
    {
        $message = $status['error'] ?? Craft::t('transcoder', 'GIF encoding failed');

        if (!empty($status['ffmpegCommand'])) {
            $message .= "\n\nFFmpeg command:\n" . $status['ffmpegCommand'];
        }

        if (!empty($status['ffmpegLog'])) {
            $message .= "\n\nFFmpeg log:\n" . $status['ffmpegLog'];
        }

        return $message;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return 'Encoding GIF asset #' . ($this->assetId ?? 'unknown');
    }
}
