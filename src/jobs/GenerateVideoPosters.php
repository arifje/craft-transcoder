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
 * Queue job that generates configured poster images for a video asset.
 */
class GenerateVideoPosters extends BaseJob
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
            Craft::error('Transcoder poster queue job could not find asset ID: ' . $this->assetId, __METHOD__);
            return;
        }

        try {
            Transcoder::$plugin->transcode->writeVideoPosterStatus(
                $asset,
                $this->videoOptions,
                [
                    'status' => 'queued',
                    'posterStatus' => 'queued',
                    'posterProgress' => 0,
                    'posterMessage' => Craft::t('transcoder', 'Video posters are queued'),
                    'posterError' => '',
                ],
                $this->encodingOptions
            );

            $posters = Transcoder::$plugin->transcode->generateVideoPosters(
                $asset,
                function (string $formatHandle, int $current, int $total) use ($asset, $queue): void {
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
                ],
                $this->encodingOptions
            );

            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('transcoder', 'Generating video posters for asset #{id}', [
            'id' => $this->assetId,
        ]);
    }
}
