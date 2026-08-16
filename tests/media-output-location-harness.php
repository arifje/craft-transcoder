<?php

declare(strict_types=1);

namespace craft\base {
    class Component
    {
    }
}

namespace craft\helpers {
    class App
    {
        public static function parseEnv(mixed $value): mixed
        {
            return $value;
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
    use nystudio107\transcoder\services\Transcode;
    use nystudio107\transcoder\Transcoder as TranscoderPlugin;

    require dirname(__DIR__) . '/src/services/Transcode.php';

    final class TestPlugin
    {
        public function getSettings(): array
        {
            return [
                'transcoderPaths' => [
                    'default' => '/var/www/content/encoded/',
                    'video' => '/var/www/content/encoded/video/',
                    'thumbnail' => '/var/www/content/encoded/thumbnail/',
                    'gif' => '/var/www/content/encoded/gif/',
                ],
                'transcoderUrls' => [
                    'default' => 'https://files.example.com/encoded/',
                    'video' => 'https://files.example.com/encoded/video/',
                    'thumbnail' => 'https://files.example.com/encoded/thumbnail/',
                    'gif' => 'https://files.example.com/encoded/gif/',
                ],
            ];
        }
    }

    final class TestableTranscode extends Transcode
    {
        /** @return array{path: string, url: string} */
        public function mediaOutputLocation(string $mediaType, string $subfolder = ''): array
        {
            return $this->getMediaOutputLocation($mediaType, $subfolder);
        }
    }

    function assertSameValue(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "$label failed. Expected " . var_export($expected, true)
                . ', got ' . var_export($actual, true) . PHP_EOL);
            exit(1);
        }
    }

    TranscoderPlugin::$plugin = new TestPlugin();
    $service = new TestableTranscode();

    $videoRoot = $service->mediaOutputLocation('video');
    assertSameValue('video root path', '/var/www/content/encoded/video/', $videoRoot['path']);
    assertSameValue('video root URL', 'https://files.example.com/encoded/video', $videoRoot['url']);

    $videoEntry = $service->mediaOutputLocation('video', '4953966/');
    assertSameValue('video entry path', '/var/www/content/encoded/video/4953966/', $videoEntry['path']);
    assertSameValue('video entry URL', 'https://files.example.com/encoded/video/4953966', $videoEntry['url']);

    $thumbnailRoot = $service->mediaOutputLocation('thumbnail');
    assertSameValue('thumbnail root path', '/var/www/content/encoded/thumbnail/', $thumbnailRoot['path']);
    assertSameValue('thumbnail root URL', 'https://files.example.com/encoded/thumbnail', $thumbnailRoot['url']);

    $gifRoot = $service->mediaOutputLocation('gif');
    assertSameValue('GIF root path', '/var/www/content/encoded/gif/', $gifRoot['path']);
    assertSameValue('GIF root URL', 'https://files.example.com/encoded/gif', $gifRoot['url']);

    $fallback = $service->mediaOutputLocation('missing');
    assertSameValue('fallback path', '/var/www/content/encoded/', $fallback['path']);
    assertSameValue('fallback URL', 'https://files.example.com/encoded', $fallback['url']);

    fwrite(STDOUT, "media output location harness passed\n");
}
