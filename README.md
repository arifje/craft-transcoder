[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/nystudio107/craft-transcoder/badges/quality-score.png?b=v4)](https://scrutinizer-ci.com/g/nystudio107/craft-transcoder/?branch=v4) [![Code Coverage](https://scrutinizer-ci.com/g/nystudio107/craft-transcoder/badges/coverage.png?b=v4)](https://scrutinizer-ci.com/g/nystudio107/craft-transcoder/?branch=v4) [![Build Status](https://scrutinizer-ci.com/g/nystudio107/craft-transcoder/badges/build.png?b=v4)](https://scrutinizer-ci.com/g/nystudio107/craft-transcoder/build-status/v4) [![Code Intelligence Status](https://scrutinizer-ci.com/g/nystudio107/craft-transcoder/badges/code-intelligence.svg?b=v4)](https://scrutinizer-ci.com/code-intelligence)

# Transcoder plugin for Craft CMS 4.x

Transcode video & audio files to various formats, convert GIFs to mp4, generate video posters, and optionally watermark encoded videos.

![Screenshot](./docs/docs/resources/img/plugin-banner.jpg)

**Note**: _The license fee for this plugin is $59.00 via the Craft Plugin Store._

## Requirements

This plugin requires Craft CMS 4.0.0 or later.

Transcoder also needs [ffmpeg](https://ffmpeg.org/) and [ffprobe](https://ffmpeg.org/ffprobe.html) on every server that runs encoding work. On Ubuntu, you can install them with:

    sudo apt-get update
    sudo apt-get install ffmpeg

If you use SVG images for video watermarks, install `librsvg2-bin` on the server that runs the encoding queue so Transcoder can rasterize the SVG before passing it to ffmpeg:

    sudo apt-get install librsvg2-bin

## Installation

To install Transcoder, follow these steps:

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require nystudio107/craft-transcoder

3. Install the plugin via `./craft install/plugin transcoder` via the CLI, or in the Control Panel, go to Settings → Plugins and click the “Install” button for Transcoder.

You can also install Transcoder via the **Plugin Store** in the Craft Control Panel.

Transcoder works on Craft 4.x.

To install `ffmpeg` on Centos 6/7, you can follow the guide [How to Install FFmpeg on CentOS](https://www.vultr.com/docs/how-to-install-ffmpeg-on-centos)

If you have managed hosting, contact your sysadmin to get `ffmpeg` installed.

## Features

Settings are split into separate Control Panel tabs for video encoding, video posters, GIF encoding, and watermarks.

### Video Encoding

Transcoder can encode uploaded video assets to mp4/webm using the configured ffmpeg options.

Recent additions include:

* Enable/disable video encoding from plugin settings.
* Queue video encoding when a video asset is uploaded or saved.
* Only queue video encoding for video assets.
* Queue a missing encode from Twig/admin preview when enabled.
* Restrict which web server names are allowed to start new encoding work.
* Keep Craft queue jobs alive while ffmpeg runs, with queue progress updates.
* Detect failed or suspicious ffmpeg output and show the ffmpeg command/log in queue errors.
* Detect and clean up stale `.lock`/`.progress` files from crashed encodes.
* Optional black-bar auto-cropping for videos that were uploaded inside a black canvas.
* Configurable filename strategy: stable source-based filenames or option-based filenames.
* Legacy filename checks so existing encoded files are reused instead of accidentally re-encoding archive content.
* Prefer local asset paths when available, with asset URL fallback for multi-server setups where files are not mounted locally.

### Video Posters

Video poster generation is separate from video encoding.

You can configure poster formats in the **Video posters** settings tab:

* Enable/disable video poster generation independently.
* Define multiple poster formats by handle, width, height, and timestamp.
* Leave width/height empty for original/auto dimensions.
* Generate missing posters in a separate queue job.
* Generate posters from the original video, independently from the video encode.
* Retrieve generated posters in Twig with `craft.transcoder.getVideoPosterUrl(asset, 'handle')`.
* Retrieve all generated posters with `craft.transcoder.getVideoPosterUrls(asset)`.
* Optionally prevent black bars in wide poster formats by adding a blurred cover layer behind fitted portrait videos.

### GIF Encoding

GIF assets can be converted to mp4 so frontend templates can render them as lightweight looping video.

The GIF settings include:

* Enable/disable GIF encoding independently.
* Queue GIF encoding when GIF assets are uploaded or saved.
* Only queue GIF encoding for `image/gif` assets.
* Spread large GIF batches with a configurable queue delay.
* Fallback to the original GIF when encoding is disabled or unavailable.
* Queue a missing GIF encode from Twig/admin preview when enabled.
* Poll queue-aware GIF status with `craft.transcoder.getGifStatus()` and `craft.transcoder.getGifStatusUrl()`.

### Watermarks

Encoded videos can receive a configurable image watermark.

The **Watermark** settings tab supports:

* Enable/disable watermarking.
* Select a Craft image asset as the watermark.
* Use an environment-specific watermark path or URL as an override before falling back to the selected asset.
* PNG, JPG, and SVG source images.
* Width/height settings, with empty values meaning auto/original.
* Position options for all corners, edge centers, and center.
* Top/right/bottom/left padding.
* Opacity percentage.
* Subtle animations: fade in, fade out, fade in/out, slow rotate, and small zoom pulse.
* Optional repositioning during playback by cycling between selected positions.

SVG watermarks are rasterized to a temporary transparent PNG before ffmpeg receives them. Install `librsvg2-bin` for reliable SVG support.

For production environments where admins cannot access plugin settings, configure the watermark source in `config/transcoder.php` instead of relying on a Craft asset ID:

```php
return [
    'videoWatermarkPath' => getenv('TRANSCODER_WATERMARK_PATH') ?: '',
    // Remote fallback, used when TRANSCODER_WATERMARK_URL is not set:
    'videoWatermarkUrl' => getenv('TRANSCODER_WATERMARK_URL') ?: 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/icons/play-circle.svg',
];
```

`videoWatermarkPath` is checked first, then `videoWatermarkUrl`, then the selected Craft asset from the settings screen.
The example fallback uses a free MIT-licensed Bootstrap Icons SVG.
Admin/debug responses include watermark source diagnostics, including whether the selected path/URL/asset was active or skipped.

### Runtime Kill Switch

The Control Panel utility **Transcoder Encoding** lets admins disable encoding at runtime without changing project config.

When runtime encoding is disabled:

* Upload/save events do not start video, poster, or GIF encoding.
* Twig helpers report encoding as disabled.
* Frontend templates can show original video/GIF assets instead of generated encodes.
* Active tracked ffmpeg processes can be stopped when disabling encoding.

This is intended as an emergency switch if ffmpeg jobs start overloading a server.

## Twig Helpers

Useful helper checks:

```twig
{% set canEncode = craft.transcoder.canEncode() %}
{% set videoEncodingEnabled = craft.transcoder.isVideoEncodingEnabled() %}
{% set videoPostersEnabled = craft.transcoder.isVideoPostersEnabled() %}
{% set videoQueueEnabled = craft.transcoder.isVideoQueueEnabled() %}
{% set gifEncodingEnabled = craft.transcoder.isGifEncodingEnabled() %}
{% set gifQueueEnabled = craft.transcoder.isGifQueueEnabled() %}
```

Use `canEncode` when templates are allowed to queue/start missing encodes only on a dedicated backend:

```twig
{% set canEncode = craft.transcoder.canEncode() %}
{% set queueMissingVideo = canEncode and craft.transcoder.isVideoQueueEnabled() %}
{% set encodedVideoData = craft.transcoder.getVideoStatus(video, {}, {}, queueMissingVideo) | json_decode %}
```

Configure the allowed encoding web servers in `config/transcoder.php`. Leave the setting empty to allow all servers. Console queue workers are always allowed, and entries may also come from environment variables:

```php
'encodingServerNames' => [
    'redactie.skoften.net',
],
```

Queue-aware video status:

```twig
{% set videoOptions = {} %}
{% set encodingOptions = {} %}
{% set encodedVideoData = craft.transcoder.getVideoStatus(video, videoOptions, encodingOptions, true) | json_decode %}
{% set progressUrl = craft.transcoder.getVideoStatusUrl(video, videoOptions, encodingOptions) %}
```

Video posters:

```twig
{% set poster = craft.transcoder.getVideoPosterUrl(video, '16_9') %}
{% set posters = craft.transcoder.getVideoPosterUrls(video) %}
```

Queue-aware GIF status:

```twig
{% set gifOptions = {} %}
{% set encodedGifData = craft.transcoder.getGifStatus(image, gifOptions, true) | json_decode %}
{% set progressUrl = craft.transcoder.getGifStatusUrl(image, gifOptions) %}
```

## Documentation

Click here -> [Transcoder Documentation](https://nystudio107.com/plugins/transcoder/documentation)

## Transcoder Roadmap

Some things to do, and ideas for potential features:

* Add a console command for doing encodings via console
* Figure out a way to reliably do multi-pass video encoding
* Add audio normalization via `loudnorm` http://k.ylo.ph/2016/04/04/loudnorm.html

Brought to you by [nystudio107](https://nystudio107.com)
