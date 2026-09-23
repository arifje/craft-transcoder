<?php

declare(strict_types=1);

namespace {
    class Craft
    {
        public static object $app;

        public static function info(string $message, string $category): void
        {
        }
    }
}

namespace yii\base {
    class Component
    {
    }
}

namespace craft\helpers {
    class FileHelper
    {
        public static function createDirectory(string $path): bool
        {
            return is_dir($path) || mkdir($path, 0777, true);
        }
    }
}

namespace nystudio107\transcoder\services {
    function flock($handle, int $operation, ?int &$wouldBlock = null): bool
    {
        if ($GLOBALS['simulateLockFailure'] ?? false) {
            $wouldBlock = 0;
            return false;
        }
        return \flock($handle, $operation, $wouldBlock);
    }
}

namespace {
    use nystudio107\transcoder\models\EncodingSlot;
    use nystudio107\transcoder\services\EncodingConcurrencyService;

    require dirname(__DIR__) . '/src/models/EncodingSlot.php';
    require dirname(__DIR__) . '/src/services/EncodingConcurrencyService.php';

    final class TestApplication
    {
        public function getBasePath(): string
        {
            return dirname(__DIR__);
        }
    }

    final class TestConcurrencyService extends EncodingConcurrencyService
    {
        public function __construct(private string $directory)
        {
        }

        protected function getLockDirectory(): string
        {
            return $this->directory;
        }
    }

    function assertSlot(string $label, mixed $slot, int $number): void
    {
        if (!$slot instanceof EncodingSlot || $slot->number !== $number) {
            fwrite(STDERR, $label . ' failed' . PHP_EOL);
            exit(1);
        }
    }

    function assertNoSlot(string $label, mixed $slot): void
    {
        if ($slot !== null) {
            fwrite(STDERR, $label . ' failed; expected no available slot' . PHP_EOL);
            exit(1);
        }
    }

    Craft::$app = new TestApplication();
    $directory = sys_get_temp_dir() . '/transcoder-concurrency-test-' . getmypid();
    $service = new TestConcurrencyService($directory);

    $videoOne = $service->acquire('video', 2, 'video encode', 101);
    $videoTwo = $service->acquire('video', 2, 'video posters', 102);
    assertSlot('first video slot', $videoOne, 1);
    assertSlot('second video slot', $videoTwo, 2);
    assertNoSlot('video pool limit', $service->acquire('video', 2, 'video encode', 103));

    $gifOne = $service->acquire('gif', 1, 'GIF encode', 201);
    assertSlot('independent GIF pool', $gifOne, 1);
    assertNoSlot('GIF pool limit', $service->acquire('gif', 1, 'GIF encode', 202));

    $videoOne->release();
    $videoReplacement = $service->acquire('video', 2, 'video encode', 104);
    assertSlot('released video slot', $videoReplacement, 1);

    $videoTwo->release();
    $videoReplacement->release();
    $gifOne->release();

    $worker = proc_open([
        PHP_BINARY,
        '-r',
        '$handle = fopen($argv[1], "c+"); if (!flock($handle, LOCK_EX)) { exit(1); } '
        . 'fwrite($handle, json_encode(["pid" => getmypid()])); fflush($handle); '
        . 'echo "ready\n"; fflush(STDOUT); sleep(30);',
        $directory . '/video-1.lock',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($worker)) {
        throw new RuntimeException('Cannot start lock-holder test process');
    }
    try {
        stream_set_timeout($pipes[1], 5);
        if (trim((string)fgets($pipes[1])) !== 'ready') {
            throw new RuntimeException('Lock-holder test process did not acquire its lock');
        }
        assertNoSlot('separate worker holds slot', $service->acquire('video', 1, 'video encode', 105));
    } finally {
        proc_terminate($worker, 9);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($worker);
    }
    $recovered = $service->acquire('video', 1, 'video encode', 105);
    assertSlot('killed worker releases kernel lock', $recovered, 1);
    $recovered->release();

    // Stale metadata alone must never keep a slot busy after a worker exits.
    file_put_contents($directory . '/video-1.lock', '{"pid":99999999,"acquiredAt":1}');
    $recovered = $service->acquire('video', 1, 'video encode', 105);
    assertSlot('stale metadata recovery', $recovered, 1);
    $recovered->release();

    $GLOBALS['simulateLockFailure'] = true;
    try {
        $service->acquire('video', 1, 'video encode', 106);
        throw new LogicException('Non-contention locking errors must fail, not return a busy slot');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'could not acquire concurrency lock file: ' . $directory . '/video-1.lock')) {
            throw $e;
        }
    } finally {
        $GLOBALS['simulateLockFailure'] = false;
    }
    $recovered = $service->acquire('video', 1, 'video encode', 106);
    assertSlot('lock error closes handle', $recovered, 1);
    $recovered->release();

    unlink($directory . '/video-1.lock');
    mkdir($directory . '/video-1.lock');
    try {
        $service->acquire('video', 1, 'video encode', 107);
        throw new LogicException('An invalid lock path must fail');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'Worker effective UID:') || !str_contains($e->getMessage(), 'fopen(')) {
            throw new LogicException('Lock errors must include the native failure and worker identity', 0, $e);
        }
    } finally {
        rmdir($directory . '/video-1.lock');
    }

    foreach (glob($directory . '/*.lock') ?: [] as $path) {
        unlink($path);
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }

    fwrite(STDOUT, "encoding concurrency harness passed\n");
}
