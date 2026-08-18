# Transcoder Changelog

## 4.4.44 - 2026.08.18
### Added
* Add a confirm-first `transcoder/videos/repair` console command with Entry creation-date and Entry-ID scopes, dry-run output, legacy cleanup, optional Entry-directory orphan cleanup, and asynchronous repair queueing.
* Add separate per-server concurrency limits for video/poster FFmpeg jobs and GIF FFmpeg jobs.

### Changed
* Make the configured video filename strategy solely responsible for new video filenames, and stop adding `_asset{ID}` to new video, poster, or GIF output.
* Keep Asset-ID-qualified output from 4.4.42-4.4.43 as a legacy lookup and cleanup candidate.
* Defer capacity-limited queue work without counting it as a failed encoding attempt.

## 4.4.43 - 2026.08.16
### Fixed
* Keep automatic video, poster, and GIF output in their media-specific base directories when a newly uploaded Asset does not have a subfolder yet.
* Give new Assets a bounded queue-only settling window to reach their final Craft upload folder before output paths are inspected.

## 4.4.42 - 2026.08.13
### Added
* Add `refreshVideoAsset()` for integrations that intentionally replace a video Asset's source file.

### Changed
* Queue Transcoder-owned invalidation after replacement, wait safely for any active Asset-owned encode, then remove the automatic video, configured posters, temporary files, and matching runtime status before fresh inspection.
* Give Asset-based video and poster output an Asset-ID-qualified filename and publish output atomically, preventing collisions between same-named Assets.
* Resolve unambiguous legacy `asset.url` calls back to their Asset so existing templates use the new owned output.
* Replace 4.4.41's source-generation table design with explicit, filesystem-owned invalidation; no replacement state is stored in the database.

## 4.4.41 - 2026.08.13
### Changed
* Added source-generation tracking for replaced video Assets. This implementation was superseded by the explicit invalidation API in 4.4.42.

## 4.4.40 - 2026.07.14
### Changed
* Process automatic video, poster, and GIF upload checks through one lightweight asynchronous asset-inspection job.
* Stop automatically scanning Entry fields or requeueing existing media when Entries or Asset metadata are saved.
* Retry upload inspection with a bounded delay when Craft has not made the Asset source available yet.

## 4.3.5 - 2026.05.21
### Fixed
* Fix ffprobe summary parsing so video dimensions are available to auto-crop detection.

## 4.3.4 - 2026.05.20
### Changed
* Add detailed video auto-crop logging and status metadata for detected crop filters.

## 4.3.3 - 2026.05.20
### Fixed
* Improve video black-bar auto crop detection for TikTok-style videos with text in the black wrapper area.

## 4.3.2 - 2026.05.19
### Added
* Add an optional video black-bar crop detection setting for video encodes.

## 4.3.1 - 2026.05.19
### Added
* Add a CP Utility runtime switch for enabling/disabling all encoding work.
* Stop tracked active ffmpeg processes when encoding is disabled from the utility.

## 4.3.0 - 2026.05.17
### Added
* Add queue-based GIF encoding with queue-aware Twig status helpers.
* Add GIF settings tab with controls for enabling GIF encoding and spreading queued jobs over time.

## 4.2.0 - 2026.05.17
### Added
* Add configurable queued video poster generation with Control Panel settings.
* Add Twig helpers for reading configured video poster URLs.

## 4.1.0 - 2026.05.15
### Added
* Add queue-based video encoding with queue-aware Twig status helpers and a polling endpoint.
* Add Control Panel settings for the download endpoint and asset-upload queueing toggles.
* Add a setting to disable video encoding while keeping original video playback available.

## 4.0.2 - 2024.09.30
## Added
* Add `phpstan` and `ecs` code linting
* Add `code-analysis.yaml` GitHub action

## 4.0.1 - 2023.04.20
### Changed
* Updated the docs to use VitePress `^1.0.0-alpha.29`
* Allow for versioning of the docs

### Fixed
* Fix Asset Volume file system access for Craft 4 ([#67](https://github.com/nystudio107/craft-transcoder/pull/67/files))
* Fix progress URLs and send application/json response ([#68](https://github.com/nystudio107/craft-transcoder/pull/68))
* Fix asset thumbnails ([#69](https://github.com/nystudio107/craft-transcoder/pull/69))
* Fix GIF filename generation ([#70](https://github.com/nystudio107/craft-transcoder/pull/70))

## 4.0.0 - 2022.09.20
### Changed
* Pinned `vitepress` to `^0.22.4` pending official `1.0.0` release
* Add comments to `Makefile`s for Fig
* Use Vite `^3.1.0` & rebuild assets
* Add `allow-plugins` to `composer.json` to allow CI tests to function

### Fixed
* Remove reference to now missing `DefineAssetThumbUrlEvent::generate` property 
* Change reference to now renamed `DefineAssetThumbUrlEvent::path` property

## 4.0.0-beta.6 - 2022.04.11
### Fixed
* Fixed method signature for `Transcode::getFileInfo()` so that an Asset object can be passed into it

## 4.0.0-beta.5 - 2022.04.09
### Changed
* Added `synchronous` & `stripMetadata` to the parameters that should be excluded from the generated file name

## 4.0.0-beta.4 - 2022.04.08
### Fixed
* Fixed incorrect return types in `TranscoderVariable` that could cause exceptions to be thrown

## 4.0.0-beta.3 - 2022.03.17

### Changed

* Refactored to use `Assets::EVENT_DEFINE_THUMB_URL` now available in Craft `4.0.0-beta.2`

## 4.0.0-beta.2 - 2022.03.04

### Fixed

* Updated types for Craft CMS `4.0.0-alpha.1` via Rector

## 4.0.0-beta.1 - 2022.02.27

### Added

* Initial Craft CMS 4 compatibility
