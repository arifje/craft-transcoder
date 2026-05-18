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
    'Allow Transcoder to encode uploaded videos and queue missing encodes from Twig/admin previews. Disable this to serve original videos while keeping other plugin helpers available.' => 'Allow Transcoder to encode uploaded videos and queue missing encodes from Twig/admin previews. Disable this to serve original videos while keeping other plugin helpers available.',
    'Enable video posters' => 'Enable video posters',
    'Generate configured video poster images when videos are queued. Templates can read poster URLs without starting ffmpeg work.' => 'Generate configured video poster images when videos are queued. Templates can read poster URLs without starting ffmpeg work.',
    'Prevent black bars' => 'Prevent black bars',
    'Add a blurred cover background behind fitted video posters, so portrait videos look better in wide poster formats.' => 'Add a blurred cover background behind fitted video posters, so portrait videos look better in wide poster formats.',
    'Enable download file endpoint' => 'Enable download file endpoint',
    'Allow anonymous frontend access to the Transcoder download endpoint. Only enable this if generated files are intended to be downloadable.' => 'Allow anonymous frontend access to the Transcoder download endpoint. Only enable this if generated files are intended to be downloadable.',
    'Queue videos on asset upload' => 'Queue videos on asset upload',
    'Queue video encoding and poster generation when a new video asset is uploaded. This requires a working Craft queue runner for reliable background processing.' => 'Queue video encoding and poster generation when a new video asset is uploaded. This requires a working Craft queue runner for reliable background processing.',
    'Video filename strategy' => 'Video filename strategy',
    'Choose whether encoded video filenames should stay stable per source asset or include encoding options. Existing legacy filenames are still checked before a new encode is queued.' => 'Choose whether encoded video filenames should stay stable per source asset or include encoding options. Existing legacy filenames are still checked before a new encode is queued.',
    'Source asset' => 'Source asset',
    'Encoding options' => 'Encoding options',
    'Video poster formats' => 'Video poster formats',
    'Define the poster images Transcoder should generate for each queued video.' => 'Define the poster images Transcoder should generate for each queued video.',
    'Define the poster images Transcoder should generate for each queued video. Leave width or height empty to use the original video dimension.' => 'Define the poster images Transcoder should generate for each queued video. Leave width or height empty to use the original video dimension.',
    'Handle' => 'Handle',
    'Width' => 'Width',
    'Height' => 'Height',
    'Time' => 'Time',
    'Video' => 'Video',
    'Video posters' => 'Video posters',
    'GIF' => 'GIF',
    'Enable GIF encoding' => 'Enable GIF encoding',
    'Allow Transcoder to convert GIF assets to mp4 files.' => 'Allow Transcoder to convert GIF assets to mp4 files.',
    'Queue GIFs on asset upload' => 'Queue GIFs on asset upload',
    'Queue GIF encoding when a new GIF asset is uploaded instead of starting ffmpeg from templates.' => 'Queue GIF encoding when a new GIF asset is uploaded instead of starting ffmpeg from templates.',
    'GIF queue delay' => 'GIF queue delay',
    'Seconds to delay each queued GIF job after the previous one. This spreads large batches over time and helps avoid overloading the server.' => 'Seconds to delay each queued GIF job after the previous one. This spreads large batches over time and helps avoid overloading the server.',
    'Queued {count} GIF encode(s) for entry {id}' => 'Queued {count} GIF encode(s) for entry {id}',
    'No video assets found to queue for entry {id}' => 'No video assets found to queue for entry {id}',
    'No GIF assets found to queue for entry {id}' => 'No GIF assets found to queue for entry {id}',
    'Queued {mediaType} encode for asset {id}: {status}' => 'Queued {mediaType} encode for asset {id}: {status}',
    '{name} plugin loaded' => '{name} plugin loaded',
    '{name} cache directory cleared' => '{name} cache directory cleared',
    'Queued {count} video encode(s) for entry {id}' => 'Queued {count} video encode(s) for entry {id}',
    'Manifest file not found at: {manifestPath}' => 'Manifest file not found at: {manifestPath}',
    'Module does not exist in the manifest: {moduleName}' => 'Module does not exist in the manifest: {moduleName}'
];
