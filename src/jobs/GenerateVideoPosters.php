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
use craft\helpers\StringHelper;
use craft\queue\BaseJob;
use nystudio107\transcoder\Transcoder;
use Throwable;

/**
 * Queue job that generates configured poster images for a video asset.
 */
class GenerateVideoPosters extends BaseJob
{
    /**
     * @var int|null
     */
    public ?int $assetId = null;

    /**
     * @var string|null Ignored compatibility field for jobs serialized by 4.4.41.
     */
    public ?string $sourceGeneration = null;

    /**
     * @var string|null
     */
    public ?string $ownerTitle = null;

    /**
     * @var array
     */
    public array $videoOptions = [];

    /**
     * @var array
     */
    public array $encodingOptions = [];

    /**
     * @var int Seconds Craft should reserve for this queue job.
     */
    public int $queueTtrSeconds = 1800;

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
            Craft::error('Transcoder poster queue job could not find asset ID: ' . $this->assetId, __METHOD__);
            return;
        }

        if (Transcoder::$plugin->transcode->isTemporaryUploadAsset($asset)) {
            Craft::info('Transcoder poster queue job skipped temporary upload asset ID: ' . $this->assetId, __METHOD__);
            $this->setProgress($queue, 1, Craft::t('transcoder', 'Temporary upload skipped'));
            return;
        }

        Transcoder::$plugin->transcode->withLocalVideoSource($asset, function(?Throwable $sourceError) use ($queue, $asset): void {
            $this->executeWithSource($queue, $asset, $sourceError);
        });
    }

    /**
     * Execute with a directly reachable source or a temporary Craft-managed copy.
     */
    private function executeWithSource(mixed $queue, Asset $asset, ?Throwable $sourceError): void
    {
        if (!Transcoder::$plugin->transcode->isRuntimeEncodingEnabled()) {
            Transcoder::$plugin->transcode->writeVideoPosterStatus(
                $asset,
                $this->videoOptions,
                [
                    'status' => 'disabled',
                    'url' => '',
                    'progress' => 0,
                    'posterStatus' => 'disabled',
                    'posterProgress' => 0,
                    'posterMessage' => Craft::t('transcoder', 'Encoding disabled'),
                ],
                $this->encodingOptions
            );
            $this->setProgress($queue, 1, Craft::t('transcoder', 'Encoding disabled'));
            return;
        }

        try {
            if ($sourceError !== null) {
                throw new \RuntimeException(
                    Craft::t('transcoder', 'Original video source is not reachable yet'),
                    0,
                    $sourceError
                );
            }

            if (!Transcoder::$plugin->transcode->isAssetOriginalAvailable($asset)) {
                throw new \RuntimeException(Craft::t('transcoder', 'Original video source is not reachable yet'));
            }

            Transcoder::$plugin->transcode->writeVideoPosterStatus(
                $asset,
                $this->videoOptions,
                [
                    'status' => 'queued',
                    'posterStatus' => 'queued',
                    'posterProgress' => 0,
                    'posterMessage' => Craft::t('transcoder', 'Video posters are queued'),
                    'posterError' => '',
                    'posterQueueTtrSeconds' => $this->queueTtrSeconds,
                ],
                $this->encodingOptions
            );

            $posters = Transcoder::$plugin->transcode->generateVideoPosters(
                $asset,
                function(string $formatHandle, int $current, int $total) use ($asset, $queue): void {
                    $progress = $total > 0 ? (int)floor((($current - 1) / $total) * 100) : 0;
                    $message = Craft::t('transcoder', 'Generating video poster {current} of {total}', [
                        'current' => $current,
                        'total' => $total,
                    ]);

                    $this->setProgress($queue, $total > 0 ? (($current - 1) / $total) : 0, $message);
                    Transcoder::$plugin->transcode->writeVideoPosterStatus(
                        $asset,
                        $this->videoOptions,
                        [
                            'status' => 'queued',
                            'posterStatus' => 'generating',
                            'posterProgress' => $progress,
                            'posterMessage' => $message,
                            'posterError' => '',
                        ],
                        $this->encodingOptions
                    );
                }
            );

            $currentStatus = Transcoder::$plugin->transcode->getVideoStatusData($asset, $this->videoOptions, $this->encodingOptions);
            $videoStillRunning = !empty($currentStatus['jobId'])
                && in_array($currentStatus['status'] ?? null, ['queued', 'encoding'], true);
            $nextStatus = $videoStillRunning ? $currentStatus['status'] : 'ok';

            Transcoder::$plugin->transcode->writeVideoPosterStatus(
                $asset,
                $this->videoOptions,
                [
                    'status' => $nextStatus,
                    'posterStatus' => 'complete',
                    'posterProgress' => 100,
                    'posterMessage' => Craft::t('transcoder', 'Video posters generated'),
                    'posterError' => '',
                    'posterUrls' => $posters,
                ],
                $this->encodingOptions
            );

            $this->setProgress($queue, 1, Craft::t('transcoder', 'Video posters generated'));
        } catch (Throwable $e) {
            $message = Craft::t('transcoder', 'Video poster generation failed for asset #{id}: {message}', [
                'id' => $asset->id,
                'message' => $e->getMessage(),
            ]);
            Craft::error($message, __METHOD__);

            if ($this->retryLater($queue, $asset, $message, $e)) {
                return;
            }

            Transcoder::$plugin->transcode->writeVideoPosterStatus(
                $asset,
                $this->videoOptions,
                [
                    'status' => 'error',
                    'url' => '',
                    'progress' => 0,
                    'error' => $message,
                    'posterStatus' => 'error',
                    'posterProgress' => 0,
                    'posterError' => $message,
                    'posterMessage' => Craft::t('transcoder', 'Video poster generation failed'),
                    'posterQueueTtrSeconds' => $this->queueTtrSeconds,
                ],
                $this->encodingOptions
            );

            throw $e;
        }
    }

    /**
     * Queue the next poster generation attempt.
     *
     * @param mixed $queue
     * @param Asset $asset
     * @param string $errorMessage
     * @param Throwable $e
     * @return bool
     */
    protected function retryLater(mixed $queue, Asset $asset, string $errorMessage, Throwable $e): bool
    {
        $maxRetries = max(0, $this->maxRetries);
        if ($this->attempt > $maxRetries) {
            return false;
        }

        $delay = max(0, $this->retryDelaySeconds);
        $nextAttempt = $this->attempt + 1;
        $totalAttempts = $maxRetries + 1;
        $message = Craft::t('transcoder', 'Retrying video poster generation attempt {attempt} of {total} in {seconds}s', [
            'attempt' => $nextAttempt,
            'total' => $totalAttempts,
            'seconds' => $delay,
        ]);

        $queueTtrSeconds = max(1, $this->queueTtrSeconds);
        $jobId = Craft::$app->getQueue()->ttr($queueTtrSeconds)->delay($delay)->push(new self([
            'assetId' => $asset->id,
            'ownerTitle' => $this->ownerTitle,
            'videoOptions' => $this->videoOptions,
            'encodingOptions' => $this->encodingOptions,
            'queueTtrSeconds' => $queueTtrSeconds,
            'attempt' => $nextAttempt,
            'maxRetries' => $maxRetries,
            'retryDelaySeconds' => $delay,
        ]));

        Craft::warning($message . ': ' . $e->getMessage(), __METHOD__);
        Transcoder::$plugin->transcode->writeVideoPosterStatus(
            $asset,
            $this->videoOptions,
            [
                'status' => 'queued',
                'url' => '',
                'progress' => 0,
                'error' => '',
                'posterStatus' => 'queued',
                'posterProgress' => 0,
                'posterMessage' => $message,
                'posterError' => '',
                'posterLastError' => $errorMessage,
                'posterJobId' => $jobId,
                'posterRetryAttempt' => $nextAttempt,
                'posterRetryTotalAttempts' => $totalAttempts,
                'posterRetryDelaySeconds' => $delay,
                'posterQueueTtrSeconds' => $queueTtrSeconds,
            ],
            $this->encodingOptions
        );
        $this->setProgress($queue, 1, $message);

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $description = Craft::t('transcoder', 'Generating video posters for asset #{id}', [
            'id' => $this->assetId,
        ]);
        $ownerTitle = trim((string)$this->ownerTitle);

        if ($ownerTitle !== '') {
            $description .= ' - ' . StringHelper::truncate($ownerTitle, 25);
        }

        if ($this->attempt > 1) {
            $description .= ' (attempt ' . $this->attempt . ')';
        }

        return $description;
    }
}
