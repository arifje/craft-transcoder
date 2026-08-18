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
* Queue video encoding only when a new video asset is uploaded.
* Keep Entry saves and existing asset metadata saves free of automatic Transcoder scanning.
* Enqueue one lightweight upload-inspection job, then perform source, output and status checks asynchronously.
* Delay video jobs created by upload inspection so asset renaming/moving plugins can settle the final filename before encoding starts.
* Only queue video encoding for video assets.
* Queue a missing encode from Twig/admin preview when enabled.
* Restrict which web server names are allowed to start new encoding work.
* Keep Craft queue jobs alive while ffmpeg runs, with queue progress updates.
* Configure the queue timeout/TTR for long video encoding and poster jobs.
* Limit concurrent video/poster FFmpeg work independently on each encoding server.
* Retry failed video encode jobs after a configurable delay before marking them as failed.
* Detect failed or suspicious ffmpeg output and show the ffmpeg command/log in queue errors.
* Detect and clean up stale `.lock`/`.progress` files from crashed encodes.
* Optional black-bar auto-cropping for videos that were uploaded inside a black canvas.
* Configurable filename strategy: stable source-based filenames or option-based filenames.
* Legacy filename checks so existing encoded files are reused instead of accidentally re-encoding archive content.
* Completed asset-status checks so an encode created before a source filename rename can be reused instead of queued again.
* Prefer local asset paths when available, with asset URL fallback for multi-server setups where files are not mounted locally.

### Video Posters

Video poster generation is separate from video encoding.

You can configure poster formats in the **Video posters** settings tab:

* Enable/disable video poster generation independently.
* Define multiple poster formats by handle, width, height, and timestamp.
* Leave width/height empty for original/auto dimensions.
* Clamp poster timestamps to the source video duration so short clips still generate posters.
* Generate missing posters in a separate queue job.
* Delay newly queued poster jobs briefly so freshly uploaded files have time to become readable.
* Retry failed video poster jobs after a configurable delay before marking them as failed.
* Generate posters from the original video, independently from the video encode.
* Retrieve generated posters in Twig with `craft.transcoder.getVideoPosterUrl(asset, 'handle')`.
* Retrieve all generated posters with `craft.transcoder.getVideoPosterUrls(asset)`.
* Optionally prevent black bars in wide poster formats by adding a blurred cover layer behind fitted portrait videos.

### GIF Encoding

GIF assets can be converted to mp4 so frontend templates can render them as lightweight looping video.

The GIF settings include:

* Enable/disable GIF encoding independently.
* Queue GIF encoding only when a new GIF asset is uploaded.
* Retry upload inspection asynchronously when Craft has not resolved the Asset or its source yet.
* Only queue GIF encoding for `image/gif` assets.
* Spread large GIF batches with a configurable queue delay.
* Allow a separate, higher concurrent GIF job limit.
* Fallback to the original GIF when encoding is disabled or unavailable.
* Queue a missing GIF encode from Twig/admin preview when enabled.
* Poll queue-aware GIF status with `craft.transcoder.getGifStatus()` and `craft.transcoder.getGifStatusUrl()`.

### Automatic Upload Processing

When `queueVideosOnSave` or `queueGifsOnSave` is enabled, Transcoder reacts only to Craft's after-save event for a brand-new Asset. The upload request performs cheap ID, new-asset, media-type and setting checks, then pushes one `InspectMediaAsset` queue job and returns.

The inspection job reloads the Asset and performs source availability, existing output, poster, active-job and runtime-status checks in the queue. It then uses the existing queueing methods to add `EncodeVideo`, `GenerateVideoPosters` or `EncodeGif` only when required. Temporarily unavailable Assets, sources, and final upload folders are retried after `mediaInspectionRetryDelaySeconds`, up to `mediaInspectionMaxRetries` retries. If an Asset legitimately remains in its volume root, processing continues after that bounded settling window and writes to the media-specific video, thumbnail, or GIF directory.

Active FFmpeg work is limited independently on each encoding server. Video encodes and video poster jobs share the `videoMaxConcurrentJobs` pool (default `1`), while GIF encodes use `gifMaxConcurrentJobs` (default `4`). When a pool is full, the job returns to Craft's queue after `encodingConcurrencyRetryDelaySeconds` without consuming an encoding retry. This allows queue workers to keep processing unrelated Craft jobs while preventing their worker concurrency from becoming the FFmpeg concurrency.

Normal Entry saves are never scanned, and saving metadata on an existing Asset does not trigger automatic transcoding. The deprecated `queueVideosOnEntrySave` and `queueGifsOnEntrySave` configuration aliases are still accepted, but now only enable new-asset upload processing; they do not restore Entry field scanning. Public element-scanning methods remain available for explicit API calls.

Craft's dedicated asset-replacement event is intentionally not registered by this workflow, so ordinary Asset metadata saves remain inert. Integrations that intentionally replace the video source can explicitly refresh Transcoder after the replacement succeeds:

```php
$result = \nystudio107\transcoder\Transcoder::$plugin
    ->getTranscode()
    ->refreshVideoAsset($asset);
```

`refreshVideoAsset()` queues a lightweight Transcoder refresh job. That job waits without signaling processes if an Asset-owned encode is active, removes the canonical automatic encode, configured poster files, recognized legacy output, matching runtime status, and safe temporary files, then queues the normal `InspectMediaAsset` workflow when automatic video processing is enabled. Missing files are treated as already clean. Transcoder computes and validates all paths itself; callers should never derive or delete Transcoder paths. Private remote Assets are copied through Craft's native temporary-file API inside the encode/poster job and cleaned up afterwards.

New video filenames are controlled only by **Video filename strategy**. `source` uses the source filename, while `options` derives the filename from the active encoding options. The short-lived `_asset{ID}` format from Transcoder 4.4.42-4.4.43 remains a lookup and cleanup candidate, but is never used for new video, poster, or GIF output. Unambiguous calls that pass either an Asset or `asset.url` resolve to the same canonical output.

The refresh API does not create or read a source-generation database table. If 4.4.41 was installed, its now-unused table may remain harmlessly; 4.4.42 does not depend on it. Run the normal `php craft up` after updating so Craft records the published schema update; no replacement-state table is created by 4.4.42. Queue workers must share Transcoder's configured output and Craft runtime storage. Ad-hoc thumbnails outside the configured poster formats are not managed by this API.

### Repairing Video Output

The confirm-first console command can audit a bounded Entry scope, remove recognized alternate/invalid Transcoder output, and queue missing canonical videos and configured posters:

```bash
# Entries created on or after August 17, 2026
php craft transcoder/videos/repair --from=2026-08-17

# Inclusive Entry creation-date range
php craft transcoder/videos/repair --from=2026-08-17 --to=2026-08-31

# One Entry, or a comma-separated list
php craft transcoder/videos/repair --entry-id=4958309
php craft transcoder/videos/repair --entry-id=4958309,4958310

# Print the complete plan without changing anything
php craft transcoder/videos/repair --from=2026-08-17 --dry-run=1

# Also remove orphan video files from Entry-ID output directories
php craft transcoder/videos/repair --entry-id=4958309 --clean-orphans=1
```

The command recursively uses the same configured video field scope as the explicit element-scanning API. It keeps valid canonical output, lists every file proposed for deletion, skips Assets with active work, and requires a yes/no confirmation before changing anything. The optional `--clean-orphans=1` sweep removes other video files only from output directories whose final path segment exactly matches a selected Entry ID; shared and root output directories are never swept, and the sweep is skipped if any Asset could not be inspected. Original Craft Assets are never modified or removed. Missing work is added to Craft's queue and continues asynchronously after the command exits, subject to the same video concurrency limit as upload-triggered work.

### Watermarks

Encoded videos can receive a configurable image watermark.

The **Watermark** settings tab supports:

* Enable/disable watermarking.
* Configure an environment-specific watermark path or URL.
* PNG, JPG, and SVG source images.
* Width/height settings, with empty values meaning auto/original at the 720px baseline.
* Watermark dimensions and padding scale from a 720px video-width baseline so the logo keeps the same visual proportions on low and high resolution encodes.
* Position options for all corners, edge centers, and center.
* Top/right/bottom/left padding.
* Opacity percentage.
* Subtle animations: fade in, fade out, fade in/out, slow rotate, and small zoom pulse.
* Optional repositioning during playback by cycling between selected positions.

SVG watermarks are rasterized to a temporary transparent PNG before ffmpeg receives them. Install `librsvg2-bin` for reliable SVG support.

For production environments where admins cannot access plugin settings, configure the watermark source in `config/transcoder.php`:

```php
return [
    'videoWatermarkPath' => getenv('TRANSCODER_WATERMARK_PATH') ?: '',
    // Remote fallback, used when TRANSCODER_WATERMARK_URL is not set:
    'videoWatermarkUrl' => getenv('TRANSCODER_WATERMARK_URL') ?: 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/icons/play-circle.svg',
];
```

`videoWatermarkPath` is checked first, then `videoWatermarkUrl`. Existing saved asset selections are still treated as a legacy fallback.
The example fallback uses a free MIT-licensed Bootstrap Icons SVG.
Admin/debug responses include watermark source diagnostics, including whether the selected path/URL/asset was active or skipped.

### Runtime Kill Switch

The Control Panel utility **Transcoder Encoding** lets admins disable encoding at runtime without changing project config.

When runtime encoding is disabled:

* New asset upload events do not enqueue video, poster, or GIF work.
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

## GraphQL / Headless

Transcoder adds queue-aware fields to Craft's `AssetInterface`, so the same video, poster, and GIF helpers can be used from GraphQL in headless builds.

Available asset fields:

* `transcoderVideoStatus(videoOptions, encodingOptions, queueIfMissing, includeDebug)`
* `transcoderVideoStatusUrl(videoOptions, encodingOptions)`
* `transcoderVideoPosterUrl(formatHandle, generate)`
* `transcoderVideoPosterUrls(generate)`
* `transcoderGifStatus(gifOptions, queueIfMissing, includeDebug)`
* `transcoderGifStatusUrl(gifOptions)`

`videoOptions`, `encodingOptions`, and `gifOptions` are JSON-encoded strings. `queueIfMissing` defaults to `false`; when set to `true`, Transcoder still respects the runtime kill switch, the feature-specific enable/disable settings, and `encodingServerNames`.

Example video query:

```graphql
query VideoAsset($assetId: [QueryArgument], $encodingOptions: String) {
  asset(id: $assetId) {
    id
    url
    transcoderVideoStatus(
      encodingOptions: $encodingOptions
      queueIfMissing: true
      includeDebug: true
    ) {
      status
      url
      progress
      error
      warning
      posterStatus
      posterProgress
      posterMessage
      posterError
      posterUrls {
        handle
        url
      }
      debugJson
    }
    transcoderVideoStatusUrl(encodingOptions: $encodingOptions)
  }
}
```

With variables:

```json
{
  "assetId": 4893852,
  "encodingOptions": "{\"watermark\":true}"
}
```

Example GIF query:

```graphql
query GifAsset($assetId: [QueryArgument]) {
  asset(id: $assetId) {
    id
    url
    transcoderGifStatus(queueIfMissing: true) {
      status
      url
      progress
      error
    }
    transcoderGifStatusUrl
  }
}
```

For frontend progress polling, prefer `transcoderVideoStatusUrl` or `transcoderGifStatusUrl` after the initial GraphQL request. Craft GraphQL query results can be cached, which is useful for content queries but not for live queue progress.

## Documentation

Click here -> [Transcoder Documentation](https://nystudio107.com/plugins/transcoder/documentation)

## Transcoder Roadmap

Some things to do, and ideas for potential features:

* Figure out a way to reliably do multi-pass video encoding
* Add audio normalization via `loudnorm` http://k.ylo.ph/2016/04/04/loudnorm.html

Brought to you by [nystudio107](https://nystudio107.com)
