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

    foreach (glob($directory . '/*.lock') ?: [] as $path) {
        unlink($path);
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }

    fwrite(STDOUT, "encoding concurrency harness passed\n");
}
