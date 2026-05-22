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
 * Transcoder config.php
 *
 * This file exists only as a template for the Transcoder settings.
 * It does nothing on its own.
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'transcoder.php'
 * and make your changes there to override default settings.
 *
 * Once copied to 'craft/config', this file will be multi-environment aware as
 * well, so you can have different settings groups for each environment, just as
 * you do for 'general.php'
 */

return [

    // The path to the ffmpeg binary
    'ffmpegPath' => '/usr/bin/ffmpeg',

    // The path to the ffprobe binary
    'ffprobePath' => '/usr/bin/ffprobe',

    // The options to use for ffprobe
    'ffprobeOptions' => '-v quiet -print_format json -show_format -show_streams',

    // The path where the transcoded videos are stored; must have a trailing /
    // Yii2 aliases are supported here
    'transcoderPaths' => [
        'default' => '@webroot/transcoder/',
        'video' => '@webroot/transcoder/video/',
        'audio' => '@webroot/transcoder/audio/',
        'thumbnail' => '@webroot/transcoder/thumbnail/',
        'gif' => '@webroot/transcoder/gif/',
    ],

    // The URL where the transcoded videos are stored; must have a trailing /
    // Yii2 aliases are supported here
    'transcoderUrls' => [
        'default' => '@web/transcoder/',
        'video' => '@web/transcoder/video/',
        'audio' => '@web/transcoder/audio/',
        'thumbnail' => '@web/transcoder/thumbnail/',
        'gif' => '@web/transcoder/gif/',
    ],

    // Determines whether the download file endpoint should be enabled for anonymous frontend access
    'enableDownloadFileEndpoint' => false,

    // Determines whether video encoding should be enabled
    'enableVideoEncoding' => true,

    // Detect and crop black bars from uploaded videos before encoding
    'autoCropVideoBlackBars' => false,

    // Add a watermark image to encoded videos
    'enableVideoWatermark' => false,

    // Craft Asset ID selected as the video watermark image
    'videoWatermarkAsset' => null,

    // Watermark dimensions in pixels. Leave empty for original image size
    'videoWatermarkWidth' => '',
    'videoWatermarkHeight' => '',

    // Watermark position: top-left, top-center, top-right, center-left, center, center-right, bottom-left, bottom-center, bottom-right
    'videoWatermarkPosition' => 'bottom-right',

    // Watermark padding in pixels
    'videoWatermarkPaddingTop' => 24,
    'videoWatermarkPaddingRight' => 24,
    'videoWatermarkPaddingBottom' => 24,
    'videoWatermarkPaddingLeft' => 24,

    // Watermark opacity percentage
    'videoWatermarkOpacity' => 100,

    // Watermark animation: none, fade-in, fade-out, fade-in-out, rotate, pulse
    'videoWatermarkAnimation' => 'none',

    // Move the watermark between positions while the video plays
    'videoWatermarkReposition' => false,
    'videoWatermarkRepositionInterval' => 10,
    'videoWatermarkRepositionPositions' => [
        'top-left',
        'top-right',
        'bottom-right',
        'bottom-left',
    ],

    // Determines whether video poster generation should be enabled
    'enableVideoPosters' => true,

    // Adds a blurred cover background behind fitted video posters to avoid black bars
    'preventVideoPosterBlackBars' => false,

    // Determines whether GIF to mp4 encoding should be enabled
    'enableGifEncoding' => true,

    // Server names that are allowed to start new encode work from web requests.
    // Leave empty to allow all servers. Console queue workers are always allowed.
    'encodingServerNames' => [],

    // Use a md5 hash for the filenames instead of parameterized naming
    'useHashedNames' => false,

    // How encoded video filenames are generated: source or options
    'videoFilenameStrategy' => 'source',

    // if a upload location has a subfolder defined, add this to the transcoder paths too
    'createSubfolders' => true,

	// if an URL (video.url) is passed as a parameter in getVideoUrl()
	// we don't have an folderPath, so can look for it in the URL (often an entry or element id)
	'subfolderUrlSegment' => false,
	
    // Add the Clear Caches utility to the CP?
    'clearCaches' => false,

    // Queue video encoding when new video assets are uploaded. Requires enableVideoEncoding to be true
    'queueVideosOnSave' => false,

    // Legacy entry field handles to inspect for video assets when queueing entries manually
    'autoEncodeVideoFieldHandles' => [],

    // Default video options used when an asset upload queues encoding
    'autoEncodeVideoOptions' => [],

    // Extra options recorded with queued encodes, for example ['watermark' => true]
    'autoEncodeEncodingOptions' => [],

    // Queue GIF encoding when new GIF assets are uploaded
    'queueGifsOnSave' => false,

    // Legacy entry field handles to inspect for GIF assets when queueing entries manually
    'autoEncodeGifFieldHandles' => [],

    // Default GIF options used when an asset upload queues encoding
    'autoEncodeGifOptions' => [],

    // Seconds to delay each queued GIF job after the previous one
    'gifQueueDelaySeconds' => 15,

    // Poster formats generated when videos are queued
    'videoPosterFormats' => [
        '16_9' => [
            'width' => 800,
            'height' => 450,
            'timeInSecs' => 3,
        ],
        'original_3s' => [
            'timeInSecs' => 3,
        ],
        'original_1s' => [
            'timeInSecs' => 1,
        ],
    ],

    // Preset video encoders
    'videoEncoders' => [
        'h264' => [
            'fileSuffix' => '.mp4',
            'fileFormat' => 'mp4',
            'videoCodec' => 'libx264',
            'videoCodecOptions' => '-vprofile high -preset slow -crf 22',
            'audioCodec' => 'libfdk_aac',
            'audioCodecOptions' => '-async 1000',
            'threads' => '0',
        ],
        'webm' => [
            'fileSuffix' => '.webm',
            'fileFormat' => 'webm',
            'videoCodec' => 'libvpx',
            'videoCodecOptions' => '-quality good -cpu-used 0',
            'audioCodec' => 'libvorbis',
            'audioCodecOptions' => '-async 1000',
            'threads' => '0',
        ],
        'gif' => [
            'fileSuffix' => '.mp4',
            'fileFormat' => 'mp4',
            'videoCodec' => 'libx264',
            'videoCodecOptions' => '-pix_fmt yuv420p -movflags +faststart -filter:v crop=\'floor(in_w/2)*2:floor(in_h/2)*2\' ',
            'threads' => '0',
        ],
    ],

    // Preset audio encoders
    'audioEncoders' => [
        'mp3' => [
            'fileSuffix' => '.mp3',
            'fileFormat' => 'mp3',
            'audioCodec' => 'libmp3lame',
            'audioCodecOptions' => '',
            'threads' => '0',
        ],
        'aac' => [
            'fileSuffix' => '.m4a',
            'fileFormat' => 'aac',
            'audioCodec' => 'libfdk_aac',
            'audioCodecOptions' => '',
            'threads' => '0',

        ],
        'ogg' => [
            'fileSuffix' => '.ogg',
            'fileFormat' => 'ogg',
            'audioCodec' => 'libvorbis',
            'audioCodecOptions' => '',
            'threads' => '0',
        ],
    ],

    // Default options for encoded videos
    'defaultVideoOptions' => [
        // Video settings
        'videoEncoder' => 'h264',
        'videoBitRate' => '800k',
        'videoFrameRate' => 15,
        // Audio settings
        'audioBitRate' => '',
        'audioSampleRate' => '',
        'audioChannels' => '',
        // Spatial settings
        'width' => '',
        'height' => '',
        'sharpen' => true,
        // Can be 'none', 'crop', or 'letterbox'
        'aspectRatio' => 'letterbox',
        'letterboxColor' => '',
    ],

    // Default options for video thumbnails
    'defaultThumbnailOptions' => [
        'fileSuffix' => '.jpg',
        'timeInSecs' => 10,
        'width' => '',
        'height' => '',
        'sharpen' => true,
        // Can be 'none', 'crop', or 'letterbox'
        'aspectRatio' => 'letterbox',
        'letterboxColor' => '',
    ],

    // Default options for encoded audio
    'defaultAudioOptions' => [
        'audioEncoder' => 'mp3',
        'audioBitRate' => '128k',
        'audioSampleRate' => '44100',
        'audioChannels' => '2',
        'timeInSecs' => '',
        'seekInSecs' => '',
        'synchronous' => false,
        'stripMetadata' => false
    ],

    // Default options for Gif encoding
    'defaultGifOptions' => [
        'videoEncoder' => 'gif',
        'fileSuffix' => '.mp4',
        'fileFormat' => 'gif',
        'videoCodec' => 'libx264',
        'videoCodecOptions' => '-pix_fmt yuv420p -movflags +faststart -filter:v crop=\'floor(in_w/2)*2:floor(in_h/2)*2\' ',
    ],
];
