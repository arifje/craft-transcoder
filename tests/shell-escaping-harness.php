<?php

declare(strict_types=1);

namespace craft\base {
    class Component
    {
    }
}

namespace {
    class Craft
    {
        public static array $warnings = [];

        public static function warning(string $message, string $category): void
        {
            self::$warnings[] = $message;
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

    final class ShellTestPlugin
    {
        public function getSettings(): array
        {
            return [
                'videoEncoders' => ['h264' => [], 'gif' => []],
                'audioEncoders' => ['mp3' => []],
            ];
        }
    }

    final class ShellTranscode extends Transcode
    {
        public function worker(string $command, string $staged, string $final, string $progress): string
        {
            return $this->buildStagedFfmpegWorkerCommand($command, $staged, $final, $progress);
        }

        public function input(string $source): string
        {
            return $this->getFfmpegInputArgs($source);
        }

        public function scaling(array $options): string
        {
            return $this->addScalingFfmpegArgs($options, 'ffmpeg');
        }
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
    }

    TranscoderPlugin::$plugin = new ShellTestPlugin();
    $service = new ShellTranscode();

    // Caller-supplied options must not carry shell or filter-graph payloads.
    $payload = '1$(touch /tmp/pwned)';
    $sanitized = $service->sanitizeMediaOptions([
        'width' => $payload,
        'height' => '450',
        'videoFrameRate' => '15;id',
        'audioBitRate' => '128k',
        'letterboxColor' => 'black"; id; "',
        'aspectRatio' => 'crop',
        'videoEncoder' => 'nope',
        'preVideoFilters' => ['crop=100:100:0:0', 'drawtext=textfile=/etc/passwd', 'scale=1:1`id`'],
        'sharpen' => true,
        'unknown' => '$(id)',
    ]);
    check(!array_key_exists('width', $sanitized), 'Injected width must be dropped');
    check(($sanitized['height'] ?? null) === '450', 'Valid height must be kept');
    check(!array_key_exists('videoFrameRate', $sanitized), 'Injected frame rate must be dropped');
    check(($sanitized['audioBitRate'] ?? null) === '128k', 'Valid bitrate must be kept');
    check(!array_key_exists('letterboxColor', $sanitized), 'Injected letterbox color must be dropped');
    check(($sanitized['aspectRatio'] ?? null) === 'crop', 'Valid aspect ratio must be kept');
    check(!array_key_exists('videoEncoder', $sanitized), 'Unknown encoder handle must be dropped');
    check(($sanitized['preVideoFilters'] ?? null) === ['crop=100:100:0:0'], 'Only safe filters may be kept');
    check(($sanitized['sharpen'] ?? null) === true, 'Booleans must be kept');
    check(!array_key_exists('unknown', $sanitized), 'Unknown option with shell characters must be dropped');

    // Filter graphs are passed as a single quoted shell argument.
    $scaled = $service->scaling([
        'width' => 320,
        'height' => 240,
        'aspectRatio' => 'letterbox',
        'letterboxColor' => 'black',
        'preVideoFilters' => ['crop=100:100:0:0'],
    ]);
    check(str_contains($scaled, " -vf 'crop=100:100:0:0,scale=320:240"), 'Filter graph must be single-quoted: ' . $scaled);
    check(!str_contains($scaled, '-vf "'), 'Filter graph must not use double quotes');

    // Source, staged, final and progress paths are escaped.
    $input = $service->input("/videos/a\$(id)'b.mp4");
    check(str_contains($input, "-protocol_whitelist file -i '/videos/a\$(id)'\\''b.mp4'"), 'Local input must be escaped and restricted: ' . $input);
    check(str_contains($service->input('https://example.com/a.mp4'), '-protocol_whitelist http,https,tcp,tls,crypto'), 'Remote input protocols must be restricted');

    $worker = $service->worker('ffmpeg -y x', '/out/a$(id).mp4.part-1', '/out/a$(id).mp4', '/work/a$(id).mp4.progress');
    check(str_contains($worker, "staged_file='/out/a\$(id).mp4.part-1'"), 'Staged file must be escaped');
    check(str_contains($worker, "mv -f \"\$staged_file\" '/out/a\$(id).mp4'"), 'Final file must be escaped');
    check(str_contains($worker, "1> '/work/a\$(id).mp4.progress'"), 'Progress file must be escaped');

    // Encoding options are reduced to known keys so status keys stay bounded.
    check($service->normalizeEncodingOptions(['watermark' => 1, 'x' => 'y']) === ['watermark' => true], 'Encoding options must be normalized');
    check($service->normalizeEncodingOptions(['x' => 'y']) === [], 'Unknown encoding options must be dropped');

    // Public status output hides commands, logs and paths.
    $public = $service->getPublicStatus([
        'status' => 'error',
        'url' => '',
        'error' => 'failed at /var/www/secret.mp4',
        'ffmpegCommand' => 'ffmpeg -i /var/www/secret.mp4',
        'ffmpegLog' => 'log',
        'source' => '/var/www/secret.mp4',
        'jobId' => 12,
    ]);
    check(!isset($public['ffmpegCommand']) && !isset($public['ffmpegLog']) && !isset($public['source']), 'Public status must hide diagnostics');
    check(!str_contains((string)$public['error'], '/var/www'), 'Public error must not include paths');
    check(($public['jobId'] ?? null) === 12, 'Public status must keep job id');
    check(isset($service->getPublicStatus(['ffmpegLog' => 'x'], true)['ffmpegLog']), 'Detailed status must keep diagnostics');

    fwrite(STDOUT, "shell escaping harness passed\n");
}
