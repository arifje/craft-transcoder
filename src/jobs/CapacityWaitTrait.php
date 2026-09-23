<?php

namespace nystudio107\transcoder\jobs;

use Craft;
use nystudio107\transcoder\models\Settings;
use nystudio107\transcoder\Transcoder;
use RuntimeException;
use Throwable;

/**
 * Carry the capacity deadline across delayed replacement jobs, not encoding attempts.
 */
trait CapacityWaitTrait
{
    public ?int $capacityWaitStartedAt = null;

    protected function beginCapacityWait(): void
    {
        $this->capacityWaitStartedAt ??= time();
        /** @var Settings $settings */
        $settings = Transcoder::$plugin->getSettings();
        $limit = max(1, (int)$settings->encodingConcurrencyMaxWaitSeconds);
        if (time() - $this->capacityWaitStartedAt >= $limit) {
            throw new RuntimeException(sprintf(
                'Transcoder stopped waiting for an FFmpeg concurrency slot after %d seconds. '
                . 'No replacement job was queued. Check running queue workers and concurrency lock holder logs; '
                . 'a worker can hold a slot before FFmpeg starts. Retry this failed job after recovery '
                . 'or increase encodingConcurrencyMaxWaitSeconds for a legitimate backlog.',
                $limit
            ));
        }
    }

    /**
     * A delayed successor already exists; a status failure must not fail its predecessor too.
     */
    protected function recordCapacityDeferral(int|string|null $jobId, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Craft::warning('Transcoder already queued capacity replacement job #' . $jobId
                . ', but could not update its status: ' . $e->getMessage(), __METHOD__);
        }
    }
}
