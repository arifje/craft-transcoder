<?php

declare(strict_types=1);

namespace {
    class Craft
    {
        public static object $app;
        public static array $warnings = [];

        public static function t(string $category, string $message, array $params = []): string
        {
            return $message;
        }

        public static function info(string $message, string $category): void
        {
        }

        public static function warning(string $message, string $category): void
        {
            self::$warnings[] = $message;
        }
    }
}

namespace craft\elements {
    class Asset
    {
        public int $id = 123;
    }
}

namespace craft\queue {
    class BaseJob
    {
        public function __construct(array $config = [])
        {
            foreach ($config as $key => $value) {
                $this->$key = $value;
            }
        }

        protected function setProgress($queue, float $progress, string $label): void
        {
            if ($queue->failProgress) {
                throw new \RuntimeException('Progress storage unavailable');
            }
        }
    }
}

namespace nystudio107\transcoder {
    class Transcoder
    {
        public static object $plugin;
    }
}

namespace {
    use craft\elements\Asset;
    use nystudio107\transcoder\jobs\EncodeGif;
    use nystudio107\transcoder\jobs\EncodeVideo;
    use nystudio107\transcoder\jobs\GenerateVideoPosters;
    use nystudio107\transcoder\Transcoder;

    require dirname(__DIR__) . '/src/jobs/CapacityWaitTrait.php';
    require dirname(__DIR__) . '/src/jobs/EncodeVideo.php';
    require dirname(__DIR__) . '/src/jobs/EncodeGif.php';
    require dirname(__DIR__) . '/src/jobs/GenerateVideoPosters.php';

    $queue = new class() {
        public array $jobs = [];
        public bool $failPush = false;
        public bool $failProgress = false;
        public int $delaySeconds = 0;

        public function ttr(int $seconds): self
        {
            return $this;
        }

        public function delay(int $seconds): self
        {
            $this->delaySeconds = $seconds;
            return $this;
        }

        public function push(object $job): int
        {
            if ($this->failPush) {
                throw new RuntimeException('Queue unavailable');
            }
            $this->jobs[] = unserialize(serialize($job));
            return count($this->jobs);
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
    $transcode = new class() {
        public bool $failRead = false;
        public bool $failWrite = false;

        public function __call(string $method, array $args): array
        {
            if ((str_starts_with($method, 'get') && $this->failRead)
                || (str_starts_with($method, 'write') && $this->failWrite)) {
                throw new RuntimeException('Status storage unavailable');
            }
            return [];
        }
    };
    Transcoder::$plugin = new class($transcode) {
        public function __construct(public object $transcode)
        {
        }

        public function getSettings(): object
        {
            return (object)['encodingConcurrencyRetryDelaySeconds' => 15, 'encodingConcurrencyMaxWaitSeconds' => 3600];
        }
    };
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new LogicException($message);
        }
    }

    foreach ([EncodeVideo::class, GenerateVideoPosters::class, EncodeGif::class] as $class) {
        $method = new ReflectionMethod($class, 'deferForCapacity');
        $asset = new Asset();
        $job = new $class();
        $queue->jobs = [];
        $method->invoke($job, $queue, $asset);
        check(count($queue->jobs) === 1 && $queue->delaySeconds === 15, "$class must queue one delayed successor");
        $next = $queue->jobs[0];
        check($next->capacityWaitStartedAt === $job->capacityWaitStartedAt && $next->attempt === 1, "$class must persist deadline without consuming failure attempt");
        $method->invoke($next, $queue, $asset);
        check($queue->jobs[1]->capacityWaitStartedAt === $job->capacityWaitStartedAt, "$class must not reset deadline");

        $next->capacityWaitStartedAt = time() - 3601;
        try {
            $method->invoke($next, $queue, $asset);
            throw new LogicException("$class must stop exhausted capacity waits");
        } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'No replacement job was queued'), 'Explicit exhaustion error required');
        }
        check(count($queue->jobs) === 2, "$class must not push at exhaustion");

        foreach (['failRead', 'failPush', 'failWrite', 'failProgress'] as $failure) {
            $queue->jobs = [];
            $target = in_array($failure, ['failRead', 'failWrite'], true) ? $transcode : $queue;
            $target->$failure = true;
            $failed = false;
            try {
                $method->invoke(new $class(), $queue, $asset);
            } catch (RuntimeException) {
                $failed = true;
            } finally {
                $target->$failure = false;
            }
            $beforePush = in_array($failure, ['failRead', 'failPush'], true);
            check($failed === $beforePush, "$class $failure must fail only before a successor exists");
            check(count($queue->jobs) === ($beforePush ? 0 : 1), "$class $failure must not fork the retry chain");
        }
    }
    check(count(Craft::$warnings) === 6, 'Post-push failures must remain visible in logs');
    fwrite(STDOUT, "capacity deferral harness passed\n");
}
