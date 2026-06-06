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
     * @var int Current attempt number, starting at 1.
     */
    public int $attempt = 1;

    /**
     * @var int Number of retries after the initial attempt fails.
     */
    public int $maxRetries = 2;

    /**
     * @var int Seconds to wait before queueing the next attempt.
     */
    public int $retryDelaySeconds = 120;

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

        if (Transcoder::$plugin->transcode->isTemporaryUploadAsset($asset)) {
            Craft::info('Transcoder GIF queue job skipped temporary upload asset ID: ' . $this->assetId, __METHOD__);
            $this->setProgress($queue, 1, Craft::t('transcoder', 'Temporary upload skipped'));
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
            if (!Transcoder::$plugin->transcode->isAssetOriginalAvailable($asset)) {
                throw new \RuntimeException(Craft::t('transcoder', 'Original GIF source is not reachable yet'));
            }

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

            if (is_array($status) && Transcoder::$plugin->transcode->isOriginalGifMissingStatus($status)) {
                throw new \RuntimeException($this->formatErrorMessage($status));
            }

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

            if ($this->retryLater($queue, $asset, $status, $e)) {
                return;
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
     * Queue the next GIF encode attempt when the failure looks transient.
     *
     * @param mixed $queue
     * @param Asset $asset
     * @param array $status
     * @param Throwable $e
     * @return bool
     */
    protected function retryLater(mixed $queue, Asset $asset, array $status, Throwable $e): bool
    {
        $maxRetries = max(0, $this->maxRetries);
        if ($this->attempt > $maxRetries || !$this->shouldRetry($e, $status)) {
            return false;
        }

        $delay = max(0, $this->retryDelaySeconds);
        $nextAttempt = $this->attempt + 1;
        $totalAttempts = $maxRetries + 1;
        $message = Craft::t('transcoder', 'Retrying GIF encode attempt {attempt} of {total} in {seconds}s', [
            'attempt' => $nextAttempt,
            'total' => $totalAttempts,
            'seconds' => $delay,
        ]);

        $queuedJob = new self([
            'assetId' => $asset->id,
            'gifOptions' => $this->gifOptions,
            'attempt' => $nextAttempt,
            'maxRetries' => $maxRetries,
            'retryDelaySeconds' => $delay,
        ]);

        $craftQueue = Craft::$app->getQueue();
        $jobId = method_exists($craftQueue, 'delay')
            ? $craftQueue->delay($delay)->push($queuedJob)
            : $craftQueue->push($queuedJob);

        Craft::warning($message . ': ' . $e->getMessage(), __METHOD__);
        Transcoder::$plugin->transcode->writeGifStatus(
            $asset,
            $this->gifOptions,
            array_merge($status, [
                'status' => 'queued',
                'url' => '',
                'progress' => 0,
                'jobId' => $jobId,
                'info' => $message,
                'error' => '',
                'retryLastError' => $status['error'] ?? $e->getMessage(),
                'retryAttempt' => $nextAttempt,
                'retryTotalAttempts' => $totalAttempts,
                'retryDelaySeconds' => $delay,
            ])
        );
        $this->setProgress($queue, 1, $message);

        return true;
    }

    /**
     * Return whether a GIF failure should be retried.
     *
     * @param Throwable $e
     * @param array $status
     * @return bool
     */
    protected function shouldRetry(Throwable $e, array $status): bool
    {
        $message = strtolower($e->getMessage() . ' ' . $this->formatErrorMessage($status));
        $retryableNeedles = [
            'process crashed',
            'ffmpeg error',
            'timed out',
            'timeout',
            'invalid output file',
            'suspicious output file',
            'no encoded output file',
            'ffmpeg log contains errors',
            'original gif source is not reachable yet',
            'original gif not found',
        ];

        foreach ($retryableNeedles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
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
        $description = 'Encoding GIF asset #' . ($this->assetId ?? 'unknown');

        if ($this->attempt > 1) {
            $description .= ' (attempt ' . $this->attempt . ')';
        }

        return $description;
    }
}
