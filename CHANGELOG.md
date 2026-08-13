# Transcoder Changelog

## 4.4.41 - 2026.08.13
### Added
* Re-encode videos and regenerate configured posters when Craft replaces a video Asset file.
* Track a shared per-Asset source generation so replacement output remains correct across web and queue workers.
* Resolve legacy video source URL/path arguments back to their versioned Asset when the match is unambiguous.

### Changed
* Give replacement generations immutable video, poster, and status identities instead of reusing an existing derivative.
* Snapshot replacement sources for queued FFmpeg work and isolate superseded jobs from the current generation.
* Publish videos and posters through run-specific staging files so only complete output becomes visible.

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
