<?php
/**
 * Transcoder plugin for Craft CMS
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
 * Invalidates automatic derivatives after another integration replaces a video.
 */
class RefreshVideoAsset extends BaseJob
{
    private const ACTIVE_RETRY_DELAY_SECONDS = 15;

    /**
     * @var int|null
     */
    public ?int $assetId = null;

    /**
     * @var int Current wait attempt, starting at 1.
     */
    public int $attempt = 1;

    /**
     * @var int Maximum number of waits for an active encoder.
     */
    public int $maxAttempts = 372;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $asset = Asset::find()->id($this->assetId)->one();
        if (!$asset instanceof Asset) {
            Craft::warning('Transcoder refresh skipped missing video asset #' . ($this->assetId ?? 'unknown'), __METHOD__);
            return;
        }

        $result = Transcoder::$plugin->transcode->performVideoAssetRefresh($asset);
        if (!empty($result['active'])) {
            if ($this->attempt >= max(1, $this->maxAttempts)) {
                throw new RuntimeException(Craft::t('transcoder', 'Video refresh timed out waiting for current encoding to finish'));
            }
            Craft::$app->getQueue()->delay(self::ACTIVE_RETRY_DELAY_SECONDS)->push(new self([
                'assetId' => $this->assetId,
                'attempt' => $this->attempt + 1,
                'maxAttempts' => $this->maxAttempts,
            ]));
            $this->setProgress($queue, 1, Craft::t('transcoder', 'Waiting for current video encoding to finish'));
            return;
        }
        $this->setProgress($queue, 1, Craft::t('transcoder', 'Video refresh complete'));
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('transcoder', 'Refreshing video asset #{id}', [
            'id' => $this->assetId ?? 'unknown',
        ]);
    }
}
