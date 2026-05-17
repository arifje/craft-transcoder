<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

/**
 * @author    nystudio107
 * @package   Transcode
 * @since     1.0.0
 */
return [
    'Transcoder caches' => 'Transcoder caches',
    'Enable video encoding' => 'Enable video encoding',
    'Allow Transcoder to encode videos from Twig calls and queued entry saves. Disable this to serve original videos while keeping other plugin helpers available.' => 'Allow Transcoder to encode videos from Twig calls and queued entry saves. Disable this to serve original videos while keeping other plugin helpers available.',
    'Enable video posters' => 'Enable video posters',
    'Generate configured video poster images when videos are queued. Templates can read poster URLs without starting ffmpeg work.' => 'Generate configured video poster images when videos are queued. Templates can read poster URLs without starting ffmpeg work.',
    'Enable download file endpoint' => 'Enable download file endpoint',
    'Allow anonymous frontend access to the Transcoder download endpoint. Only enable this if generated files are intended to be downloadable.' => 'Allow anonymous frontend access to the Transcoder download endpoint. Only enable this if generated files are intended to be downloadable.',
    'Queue videos on entry save' => 'Queue videos on entry save',
    'Queue video encoding when entries are saved. This requires a working Craft queue runner for reliable background encoding.' => 'Queue video encoding when entries are saved. This requires a working Craft queue runner for reliable background encoding.',
    'Queue video encoding and poster generation when entries are saved. This requires a working Craft queue runner for reliable background processing.' => 'Queue video encoding and poster generation when entries are saved. This requires a working Craft queue runner for reliable background processing.',
    'Video poster formats' => 'Video poster formats',
    'Define the poster images Transcoder should generate for each queued video.' => 'Define the poster images Transcoder should generate for each queued video.',
    'Handle' => 'Handle',
    'Width' => 'Width',
    'Height' => 'Height',
    'Time' => 'Time',
    'Video' => 'Video',
    'GIF' => 'GIF',
    'Enable GIF encoding' => 'Enable GIF encoding',
    'Allow Transcoder to convert GIF assets to mp4 files.' => 'Allow Transcoder to convert GIF assets to mp4 files.',
    'Queue GIFs on entry save' => 'Queue GIFs on entry save',
    'Queue GIF encoding when entries are saved instead of starting ffmpeg from templates.' => 'Queue GIF encoding when entries are saved instead of starting ffmpeg from templates.',
    'GIF queue delay' => 'GIF queue delay',
    'Seconds to delay each queued GIF job after the previous one. This spreads large batches over time and helps avoid overloading the server.' => 'Seconds to delay each queued GIF job after the previous one. This spreads large batches over time and helps avoid overloading the server.',
    'Queued {count} GIF encode(s) for entry {id}' => 'Queued {count} GIF encode(s) for entry {id}',
    'No video assets found to queue for entry {id}' => 'No video assets found to queue for entry {id}',
    'No GIF assets found to queue for entry {id}' => 'No GIF assets found to queue for entry {id}',
    '{name} plugin loaded' => '{name} plugin loaded',
    '{name} cache directory cleared' => '{name} cache directory cleared',
    'Queued {count} video encode(s) for entry {id}' => 'Queued {count} video encode(s) for entry {id}',
    'Manifest file not found at: {manifestPath}' => 'Manifest file not found at: {manifestPath}',
    'Module does not exist in the manifest: {moduleName}' => 'Module does not exist in the manifest: {moduleName}'
];
