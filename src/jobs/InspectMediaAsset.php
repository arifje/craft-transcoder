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
use RuntimeException;

/**
 * Queue job that inspects a newly uploaded media asset and queues missing work.
 */
class InspectMediaAsset extends BaseJob
{
    /**
     * @var int|null
     */
    public ?int $assetId = null;

    /**
     * @var string|null Immutable replacement generation; null for legacy uploads.
     */
    public ?string $sourceGeneration = null;

    /**
     * @var int Current attempt number, starting at 1.
     */
    public int $attempt = 1;

    /**
     * @var int Number of retries after the initial inspection.
     */
    public int $maxRetries = 5;

    /**
     * @var int Seconds to wait before retrying source inspection.
     */
    public int $retryDelaySeconds = 10;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        if (!$this->assetId) {
            throw new RuntimeException(Craft::t('transcoder', 'Media inspection job is missing an asset ID'));
        }

        $asset = Asset::find()->id($this->assetId)->one();
        if (!$asset instanceof Asset) {
            $this->retryLater($queue, Craft::t('transcoder', 'Asset #{id} is not available yet', [
                'id' => $this->assetId,
            ]));
            return;
        }

        if (!Transcoder::$plugin->transcode->isVideoSourceGenerationCurrent((int)$asset->id, $this->sourceGeneration)) {
            Craft::info(
                'Transcoder media inspection skipped superseded source generation for asset ID: ' . $this->assetId,
                __METHOD__
            );
            $this->setProgress($queue, 1, Craft::t('transcoder', 'Newer video source already queued'));
            return;
        }

        if (!Transcoder::$plugin->transcode->isQueueableMediaAsset($asset)) {
            $this->setProgress($queue, 1, Craft::t('transcoder', 'Asset does not require automatic transcoding'));
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('transcoder', 'Inspecting uploaded media asset'));

        if (Transcoder::$plugin->transcode->isTemporaryUploadAsset($asset)) {
            $this->retryLater($queue, Craft::t('transcoder', 'Asset #{id} is still in temporary upload storage', [
                'id' => $asset->id,
            ]));
            return;
        }

        if ($this->sourceGeneration === null && !Transcoder::$plugin->transcode->isAssetOriginalAvailable($asset)) {
            $this->retryLater($queue, Craft::t('transcoder', 'Original source for asset #{id} is not reachable yet', [
                'id' => $asset->id,
            ]));
            return;
        }

        $this->setProgress($queue, 0.5, Craft::t('transcoder', 'Checking existing Transcoder output'));
        $statuses = Transcoder::$plugin->transcode->queueMediaForAssetGeneration($asset, $this->sourceGeneration);

        foreach ($statuses as $mediaType => $status) {
            Craft::info(
                Craft::t(
                    'transcoder',
                    'Inspected {mediaType} asset {id}: {status}',
                    [
                        'mediaType' => $mediaType,
                        'id' => $asset->id,
                        'status' => $status['status'] ?? 'unknown',
                    ]
                ),
                __METHOD__
            );
        }

        $this->setProgress($queue, 1, Craft::t('transcoder', 'Media inspection complete'));
    }

    /**
     * Queue another bounded inspection attempt, or fail visibly when exhausted.
     *
     * @param mixed $queue
     * @param string $reason
     * @return void
     */
    protected function retryLater(mixed $queue, string $reason): void
    {
        $maxRetries = max(0, $this->maxRetries);
        if ($this->attempt > $maxRetries) {
            throw new RuntimeException(Craft::t(
                'transcoder',
                'Media inspection for asset #{id} stopped after {attempts} attempts: {reason}',
                [
                    'id' => $this->assetId,
                    'attempts' => $maxRetries + 1,
                    'reason' => $reason,
                ]
            ));
        }

        $delay = max(0, $this->retryDelaySeconds);
        $nextAttempt = $this->attempt + 1;
        $totalAttempts = $maxRetries + 1;
        $message = Craft::t(
            'transcoder',
            'Retrying media inspection attempt {attempt} of {total} in {seconds}s: {reason}',
            [
                'attempt' => $nextAttempt,
                'total' => $totalAttempts,
                'seconds' => $delay,
                'reason' => $reason,
            ]
        );

        Craft::$app->getQueue()->delay($delay)->push(new self([
            'assetId' => $this->assetId,
            'sourceGeneration' => $this->sourceGeneration,
            'attempt' => $nextAttempt,
            'maxRetries' => $maxRetries,
            'retryDelaySeconds' => $delay,
        ]));

        Craft::warning($message, __METHOD__);
        $this->setProgress($queue, 1, $message);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $description = Craft::t('transcoder', 'Inspecting media asset #{id}', [
            'id' => $this->assetId ?? 'unknown',
        ]);

        if ($this->attempt > 1) {
            $description .= ' (attempt ' . $this->attempt . ')';
        }

        return $description;
    }
}
