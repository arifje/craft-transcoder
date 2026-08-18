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
        public ?int $id = null;
        public string $filename = '';

        public function getUrl(): string
        {
            return 'https://files.example.com/videos/' . $this->filename;
        }
    }
}

namespace yii\validators {
    class UrlValidator
    {
        public function validate(mixed $value, mixed &$error = null): bool
        {
            return str_starts_with((string)$value, 'http://') || str_starts_with((string)$value, 'https://');
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
    use nystudio107\transcoder\services\Transcode;
    use nystudio107\transcoder\Transcoder as TranscoderPlugin;

    require dirname(__DIR__) . '/src/services/Transcode.php';

    final class FilenameSettings implements \ArrayAccess
    {
        public bool $useHashedNames = false;
        public string $videoFilenameStrategy = 'source';

        public function offsetExists(mixed $offset): bool
        {
            return property_exists($this, (string)$offset);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->{(string)$offset};
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->{(string)$offset} = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
        }
    }

    final class FilenamePlugin
    {
        public FilenameSettings $settings;

        public function __construct()
        {
            $this->settings = new FilenameSettings();
        }

        public function getSettings(): FilenameSettings
        {
            return $this->settings;
        }
    }

    final class FilenameTranscode extends Transcode
    {
        public function canonicalVideoFilename(Asset $asset, array $options, string $strategy): string
        {
            return $this->getVideoEncodedFilename($asset, $options, $strategy);
        }

        public function legacyVideoFilename(Asset $asset, array $options, string $strategy): string
        {
            return $this->getLegacyAssetIdVideoEncodedFilename($asset, $options, $strategy);
        }

        public function canonicalPosterFilename(Asset $asset, array $options): string
        {
            return $this->getFilename($asset, $options, ['fileSuffix']);
        }

        public function legacyPosterFilename(Asset $asset, array $options): string
        {
            return $this->getLegacyAssetIdFilename($asset, $options, ['fileSuffix']);
        }

        /** @return string[] */
        public function videoCandidates(Asset $asset, array $options, string $primary): array
        {
            return $this->getVideoFilenameCandidates($asset, $asset->getUrl(), $options, $primary);
        }

        protected function getAssetPath(Asset|string $filePath): string
        {
            return $filePath instanceof Asset ? '/videos/' . $filePath->filename : $filePath;
        }
    }

    function assertSameFilename(string $label, string $expected, string $actual): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "$label failed. Expected $expected, got $actual" . PHP_EOL);
            exit(1);
        }
    }

    function assertFilenameDoesNotContain(string $label, string $needle, string $actual): void
    {
        if (str_contains($actual, $needle)) {
            fwrite(STDERR, "$label failed. Unexpected $needle in $actual" . PHP_EOL);
            exit(1);
        }
    }

    function assertFilenameListContains(string $label, string $needle, array $actual): void
    {
        if (!in_array($needle, $actual, true)) {
            fwrite(STDERR, "$label failed. Missing $needle" . PHP_EOL);
            exit(1);
        }
    }

    $plugin = new FilenamePlugin();
    TranscoderPlugin::$plugin = $plugin;
    $service = new FilenameTranscode();
    $asset = new Asset();
    $asset->id = 4958326;
    $asset->filename = 'asset-4b1fafc4f47fcd1cc07f0e9245cf771a.mp4';
    $videoOptions = [
        'videoEncoder' => 'h264',
        'width' => 800,
        'height' => 450,
        'fileSuffix' => '.mp4',
    ];

    assertSameFilename(
        'source strategy canonical filename',
        'asset-4b1fafc4f47fcd1cc07f0e9245cf771a.mp4',
        $service->canonicalVideoFilename($asset, $videoOptions, 'source')
    );
    assertSameFilename(
        'source strategy legacy Asset-ID filename',
        'asset-4b1fafc4f47fcd1cc07f0e9245cf771a_asset4958326.mp4',
        $service->legacyVideoFilename($asset, $videoOptions, 'source')
    );
    assertSameFilename(
        'options strategy canonical filename',
        'asset-4b1fafc4f47fcd1cc07f0e9245cf771a_800w_450h.mp4',
        $service->canonicalVideoFilename($asset, $videoOptions, 'options')
    );
    assertFilenameDoesNotContain(
        'options strategy canonical identity',
        '_asset4958326',
        $service->canonicalVideoFilename($asset, $videoOptions, 'options')
    );
    $videoCandidates = $service->videoCandidates(
        $asset,
        $videoOptions,
        $service->canonicalVideoFilename($asset, $videoOptions, 'source')
    );
    assertFilenameListContains(
        'legacy Asset-ID candidate',
        'asset-4b1fafc4f47fcd1cc07f0e9245cf771a_asset4958326.mp4',
        $videoCandidates
    );
    assertSameFilename(
        'legacy Asset-ID remote lookup priority',
        'asset-4b1fafc4f47fcd1cc07f0e9245cf771a_asset4958326.mp4',
        $videoCandidates[1] ?? ''
    );

    $posterOptions = [
        'width' => 800,
        'height' => 450,
        'fileSuffix' => '.jpg',
    ];
    assertSameFilename(
        'poster canonical filename',
        'asset-4b1fafc4f47fcd1cc07f0e9245cf771a_800w_450h.jpg',
        $service->canonicalPosterFilename($asset, $posterOptions)
    );
    assertSameFilename(
        'poster legacy Asset-ID filename',
        'asset-4b1fafc4f47fcd1cc07f0e9245cf771a_asset4958326_800w_450h.jpg',
        $service->legacyPosterFilename($asset, $posterOptions)
    );

    fwrite(STDOUT, "media filename strategy harness passed\n");
}
