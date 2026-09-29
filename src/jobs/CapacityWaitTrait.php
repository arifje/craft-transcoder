<?php

namespace nystudio107\transcoder\jobs;

use Craft;
use nystudio107\transcoder\Transcoder;
use RuntimeException;
use Throwable;

/**
 * Carry the capacity deadline across delayed replacement jobs, not encoding attempts,
 * and keep each execution inside the queue worker's TTR.
 */
trait CapacityWaitTrait
{
    /**
     * Craft pulls lower priority values first; new jobs default to 1024.
     */
    public const CAPACITY_REPLACEMENT_PRIORITY = 512;

    public ?int $capacityWaitStartedAt = null;

    /**
     * @var int|null Unix time the current execution started; not carried to replacement jobs.
     */
    private ?int $executionStartedAt = null;

    /**
     * Record when this execution started so waits are measured against the full TTR,
     * including slot acquisition and source downloads.
     */
    protected function startExecutionClock(): void
    {
        $this->executionStartedAt = time();
    }

    /**
     * Return the latest time this execution may keep waiting on ffmpeg.
     *
     * Craft's CLI worker hard-kills a job when its TTR expires, so the job must
     * finish (and stop ffmpeg) with a margin to spare.
     */
    protected function getExecutionDeadline(int $ttrSeconds): int
    {
        $ttrSeconds = max(1, $ttrSeconds);
        $margin = min(60, max(5, intdiv($ttrSeconds, 10)));

        return ($this->executionStartedAt ?? time()) + max(1, $ttrSeconds - $margin);
    }

    protected function beginCapacityWait(): void
    {
        $this->capacityWaitStartedAt ??= time();
        $limit = max(1, (int)Transcoder::$plugin->getSettings()->encodingConcurrencyMaxWaitSeconds);
        if (time() - $this->capacityWaitStartedAt >= $limit) {
            $message = sprintf(
                'Transcoder stopped waiting for an FFmpeg concurrency slot after %d seconds. '
                . 'No replacement job was queued. Check running queue workers and concurrency lock holder logs; '
                . 'a worker can hold a slot before FFmpeg starts. Retry this failed job after recovery '
                . 'or increase encodingConcurrencyMaxWaitSeconds for a legitimate backlog.',
                $limit
            );
            try {
                $this->onCapacityWaitExpired($message);
            } catch (Throwable $e) {
                Craft::warning('Transcoder could not record the capacity wait failure: ' . $e->getMessage(), __METHOD__);
            }

            throw new RuntimeException($message);
        }
    }

    /**
     * Record a terminal error so the status does not stay `queued` after the wait expires.
     */
    abstract protected function onCapacityWaitExpired(string $message): void;

    /**
     * Push a capacity replacement job ahead of newly queued work so the job that has
     * waited longest is not starved by fresh uploads.
     */
    protected function pushCapacityReplacement(object $job, int $delay, ?int $ttrSeconds = null): mixed
    {
        $queue = Craft::$app->getQueue();
        if ($ttrSeconds !== null) {
            $queue->ttr(max(1, $ttrSeconds));
        }

        return $queue->priority(self::CAPACITY_REPLACEMENT_PRIORITY)->delay($delay)->push($job);
    }

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
