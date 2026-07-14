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

## Queueing Media on Asset Upload

Transcoder can queue video encoding, poster generation, and GIF encoding when a new media asset is uploaded, so templates do not have to trigger ffmpeg work. Entry saves and existing Asset metadata saves are not inspected.

The `enableVideoEncoding`, `enableVideoPosters`, `enableGifEncoding`, `enableDownloadFileEndpoint`, `queueVideosOnSave`, `queueGifsOnSave`, `mediaInspectionMaxRetries`, `mediaInspectionRetryDelaySeconds`, `gifQueueDelaySeconds`, and `videoPosterFormats` settings can also be managed from the plugin’s Control Panel settings screen. Values defined in `config/transcoder.php` take precedence over values saved from the Control Panel.

```php
return [
    // Disable this to serve original videos without disabling the plugin.
    'enableVideoEncoding' => true,

    // Generate configured poster images for queued videos.
    'enableVideoPosters' => true,

    // Convert GIF assets to mp4 files.
    'enableGifEncoding' => true,

    'enableDownloadFileEndpoint' => false,
    'queueVideosOnSave' => true,
    'queueGifsOnSave' => true,

    // Retry inspection if Craft has not resolved the Asset/source yet.
    'mediaInspectionMaxRetries' => 5,
    'mediaInspectionRetryDelaySeconds' => 10,

    // Options passed to getVideoUrl() by the queue job.
    'autoEncodeVideoOptions' => [],

    // Extra options recorded with the status key.
    'autoEncodeEncodingOptions' => [
        'watermark' => true,
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

When queueing is enabled, Transcoder listens only for brand-new Assets. The upload request checks the Asset ID, the event's new-asset flag, media type and automatic queue settings, then adds one lightweight `InspectMediaAsset` job and returns. Video uploads match `video/*`; GIF uploads match `image/gif`; other Assets do not create a job.

`InspectMediaAsset` reloads the Asset and performs source availability, existing output, poster, active-job and status-file checks asynchronously. It queues the required video encode, poster or GIF jobs only after those checks. If Craft has not committed or relocated the Asset/source yet, inspection retries after `mediaInspectionRetryDelaySeconds` and stops after `mediaInspectionMaxRetries` retries.

Normal Entry saves do not run Transcoder field scanning or create automatic jobs. Saving metadata on an existing media Asset also does not requeue it. The deprecated `queueVideosOnEntrySave` and `queueGifsOnEntrySave` aliases remain compatible with existing configuration, but only enable new upload processing; they no longer cause Entry scanning.

Craft also exposes a dedicated `Assets::EVENT_AFTER_REPLACE_ASSET` event for file replacements. Transcoder intentionally does not subscribe to it as part of automatic new-upload processing. Replacement support should be added separately with an explicit policy for invalidating or replacing existing encodes and posters; ordinary metadata saves must remain inert.

Video and GIF jobs can still be delayed with `videoQueueDelaySeconds` and `gifQueueDelaySeconds` after inspection. Make sure your production environment has a queue worker or queue runner configured, otherwise queued work will only run when Craft processes queued jobs.

Brought to you by [nystudio107](https://nystudio107.com)
