<?php

declare(strict_types=1);

namespace craft\base {
    class Component
    {
    }
}
namespace craft\elements {
    class Asset
    {
    }
}
namespace yii\queue {
    class Queue
    {
        public const STATUS_WAITING = 1;
        public const STATUS_RESERVED = 2;
    }
}
namespace {
    use craft\elements\Asset;
    use nystudio107\transcoder\services\Transcode;

    class Craft
    {
        public static object $app;
    }
    require dirname(__DIR__) . '/src/services/Transcode.php';

    $queue = new class() {
        public int $state = 3;
        public bool $fail = false;
        public function status($id): int
        {
            if ($this->fail) {
                throw new RuntimeException('Queue unavailable');
            }
            return $this->state;
        }
    };
    Craft::$app = new class($queue) {
        public function __construct(private object $queue)
        {
        }
        public function getQueue(): object
        {
            return $this->queue;
        }
    };
    $service = new class() extends Transcode {
        public array $statuses = [];
        public array $removed = [];
        public bool $staging = false;
        public function recover(): bool
        {
            $this->removed = [];
            return $this->clearInactiveVideoRecoveryState(new Asset());
        }
        protected function getAutomaticVideoAssetRefreshMetadata(Asset $asset): array
        {
            return [
                'lockPaths' => [], 'statuses' => $this->statuses,
                'pathsByKind' => ['status' => ['stale.json'], 'temporary' => ['stale.progress'], 'output' => ['keep.mp4', 'keep.jpg']],
            ];
        }
        protected function hasAutomaticVideoAssetStagingFiles(Asset $asset): bool
        {
            return $this->staging;
        }
        protected function getAutomaticVideoAssetActiveMaxAge(): int
        {
            return 3600;
        }
        public function removeVideoRepairFiles(array $files): int
        {
            $this->removed = array_column($files, 'path');
            return count($files);
        }
    };
    $assert = static function(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };
    foreach (['jobId', 'posterJobId'] as $key) {
        $service->statuses = [[$key => 123, 'status' => 'error']];
        foreach ([1, 2] as $state) {
            $queue->state = $state;
            $assert(!$service->recover() && !$service->removed, 'Active queue work must stay untouched');
        }
        foreach ([3, 4] as $state) {
            $queue->state = $state;
            $assert($service->recover(), 'Finished/failed work must allow recovery');
            $assert($service->removed === ['stale.json', 'stale.progress'], 'Never delete media output');
        }
    }
    $queue->fail = true;
    try {
        $service->recover();
        throw new LogicException('Queue failure must propagate');
    } catch (RuntimeException $e) {
        $assert($e->getMessage() === 'Queue unavailable' && !$service->removed, 'Fail closed on unavailable queue');
    }
    $queue->fail = false;
    foreach ([['status' => 'encoding'], ['posterStatus' => 'generating']] as $status) {
        $service->statuses = [$status + ['updatedAt' => time()]];
        $assert(!$service->recover() && !$service->removed, 'Recent untracked processes must be preserved');
        $service->statuses = [$status + ['updatedAt' => time() - 7200]];
        $assert($service->recover(), 'Expired untracked state must allow recovery');
    }
    $service->statuses = [];
    $service->staging = true;
    $assert(!$service->recover() && !$service->removed, 'Staging output must be preserved');
    fwrite(STDOUT, "video recovery harness passed\n");
}
