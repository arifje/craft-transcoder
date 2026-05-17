---
title: Configuring Transcoder
description: Configuring Transcoder documentation for the Transcoder plugin. The Transcoder plugin allows you to transcode video & audio files to various formats, and provide video thumbnails
---
# Configuring Transcoder

The only configuration for Transcoder is in the `config.php` file, which is a multi-environment friendly way to store the default settings.  Don’t edit this file, instead copy it to `craft/config` as `transcoder.php` and make your changes there.

You will also need [ffmpeg](https://ffmpeg.org/) installed for Transcoder to work. On Ubuntu 16.04, you can do just:

```bash
    sudo apt-get update
    sudo apt-get install ffmpeg
```

To install `ffmpeg` on Centos 6/7, you can follow the guide [How to Install FFmpeg on CentOS](https://www.vultr.com/docs/how-to-install-ffmpeg-on-centos)

If you have managed hosting, contact your sysadmin to get `ffmpeg` installed.

## Queueing Media on Entry Save

Transcoder can queue video encoding, poster generation, and GIF encoding when an entry is saved, so templates do not have to trigger ffmpeg work.

The `enableVideoEncoding`, `enableVideoPosters`, `enableGifEncoding`, `enableDownloadFileEndpoint`, `queueVideosOnEntrySave`, `queueGifsOnEntrySave`, `gifQueueDelaySeconds`, and `videoPosterFormats` settings can also be managed from the plugin’s Control Panel settings screen. Values defined in `config/transcoder.php` take precedence over values saved from the Control Panel.

```php
return [
    // Disable this to serve original videos without disabling the plugin.
    'enableVideoEncoding' => true,

    // Generate configured poster images for queued videos.
    'enableVideoPosters' => true,

    // Convert GIF assets to mp4 files.
    'enableGifEncoding' => true,

    'enableDownloadFileEndpoint' => false,
    'queueVideosOnEntrySave' => true,
    'queueGifsOnEntrySave' => true,

    // Optional: limit scanning to these entry field handles.
    // Leave empty to inspect all custom fields recursively, including Matrix blocks.
    'autoEncodeVideoFieldHandles' => [
        'contentBuilder',
        'video',
    ],

    // Options passed to getVideoUrl() by the queue job.
    'autoEncodeVideoOptions' => [],

    // Extra options recorded with the status key.
    'autoEncodeEncodingOptions' => [
        'watermark' => true,
    ],

    // Optional: limit GIF scanning to these entry field handles.
    // Leave empty to inspect all custom fields recursively, including Matrix blocks.
    'autoEncodeGifFieldHandles' => [
        'contentBuilder',
    ],

    // Options passed to getGifUrl() by the queue job.
    'autoEncodeGifOptions' => [],

    // Delay each queued GIF job by this many seconds after the previous one.
    'gifQueueDelaySeconds' => 15,

    'videoPosterFormats' => [
        '16_9' => [
            'width' => 800,
            'height' => 450,
            'timeInSecs' => 3,
        ],
        'original_3s' => [
            'timeInSecs' => 3,
        ],
    ],
];
```

When queueing is enabled, Transcoder listens for saved entries, finds matching video and GIF assets in the configured fields, and adds encoding jobs to Craft’s queue. Video jobs encode video when `enableVideoEncoding` is enabled and generate configured posters when `enableVideoPosters` is enabled.

GIF jobs are intentionally load-spread. If an entry contains many GIFs, each queued GIF job is delayed by `gifQueueDelaySeconds` after the previous one. With the default value of `15`, 40 GIFs are scheduled across roughly 10 minutes instead of starting all at once. Make sure your production environment has a queue worker or queue runner configured, otherwise queued work will only run when Craft processes queued jobs.

Brought to you by [nystudio107](https://nystudio107.com)
