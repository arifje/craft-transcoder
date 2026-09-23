<?php

declare(strict_types=1);

namespace craft\base {
    class Component
    {
    }

    interface LocalFsInterface
    {
    }
}

namespace craft\elements {
    class Asset
    {
        public int $id = 123;
        public bool $failSource = false;
        public ?string $copy = null;

        public function __construct(private object $volume)
        {
        }

        public function getVolume(): object
        {
            if ($this->failSource) {
                throw new \RuntimeException('Source lookup failed');
            }
            return $this->volume;
        }

        public function getCopyOfFile(): string
        {
            if ($this->copy === null) {
                throw new \RuntimeException('Source download failed');
            }
            return $this->copy;
        }
    }
}

namespace {
    use craft\base\LocalFsInterface;
    use craft\elements\Asset;
    use nystudio107\transcoder\services\Transcode;

    require dirname(__DIR__) . '/src/services/Transcode.php';

    $service = new Transcode();
    $local = new class() implements LocalFsInterface {
    };
    $remote = new stdClass();
    foreach ([$local, $remote] as $fs) {
        $volume = new class($fs) {
            public function __construct(private object $fs)
            {
            }

            public function getFs(): object
            {
                return $this->fs;
            }
        };
        $asset = new Asset($volume);
        if ($fs === $remote) {
            $asset->copy = tempnam(sys_get_temp_dir(), 'transcoder-source-test-');
            file_put_contents($asset->copy, 'test source');
        }
        $calls = 0;
        $failure = new RuntimeException('Redis unavailable during encoding');
        try {
            $service->withLocalVideoSource($asset, function(?Throwable $sourceError) use (&$calls, $failure): void {
                $calls++;
                if ($sourceError !== null) {
                    throw new LogicException('Callback failure must not become a source failure');
                }
                throw $failure;
            });
            throw new LogicException('Callback failure must propagate');
        } catch (RuntimeException $e) {
            if ($e !== $failure || $calls !== 1) {
                throw new LogicException('Encoding callback must execute exactly once', 0, $e);
            }
        }
        if ($asset->copy !== null && file_exists($asset->copy)) {
            throw new LogicException('Downloaded source must be cleaned up on callback failure');
        }

        $asset->failSource = true;
        $calls = 0;
        $result = $service->withLocalVideoSource($asset, function(?Throwable $sourceError) use (&$calls): string {
            $calls++;
            if ($sourceError?->getMessage() !== 'Source lookup failed') {
                throw new LogicException('Source failures must still be passed to the callback');
            }
            return 'handled';
        });
        if ($result !== 'handled' || $calls !== 1) {
            throw new LogicException('Source error callback must execute exactly once');
        }
    }
    fwrite(STDOUT, "local source callback harness passed\n");
}
