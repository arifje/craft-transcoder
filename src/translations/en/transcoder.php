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
    'Enable download file endpoint' => 'Enable download file endpoint',
    'Allow anonymous frontend access to the Transcoder download endpoint. Only enable this if generated files are intended to be downloadable.' => 'Allow anonymous frontend access to the Transcoder download endpoint. Only enable this if generated files are intended to be downloadable.',
    'Queue videos on entry save' => 'Queue videos on entry save',
    'Queue video encoding when entries are saved. This requires a working Craft queue runner for reliable background encoding.' => 'Queue video encoding when entries are saved. This requires a working Craft queue runner for reliable background encoding.',
    '{name} plugin loaded' => '{name} plugin loaded',
    '{name} cache directory cleared' => '{name} cache directory cleared',
    'Queued {count} video encode(s) for entry {id}' => 'Queued {count} video encode(s) for entry {id}',
    'Manifest file not found at: {manifestPath}' => 'Manifest file not found at: {manifestPath}',
    'Module does not exist in the manifest: {moduleName}' => 'Module does not exist in the manifest: {moduleName}'
];
