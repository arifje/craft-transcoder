# Transcoder Changelog

## 5.0.4 - 2026.09.29
### Fixed
* Read-only video poster/thumbnail lookups (`generate` false) no longer run ffprobe against the source or log the full candidate list; they return before building the ffmpeg command. This removes slow, failing ffprobe calls on every page view when a poster is missing and the source is remote or unavailable.

## 5.0.3 - 2026.09.29
### Security
* Sanitize every caller-supplied media option (Twig, GraphQL, jobs) before it reaches ffmpeg: numeric options must be numeric, `aspectRatio`/`letterboxColor`/encoder handles are allowlisted, and unsafe `preVideoFilters` are dropped. GraphQL only accepts presentation options and never `preVideoFilters`; `encodingOptions` are reduced to `watermark`.
* Shell-escape all ffmpeg arguments: `-vf` filter graphs, frame/bit/sample rates, channels, seek/duration values and progress-file redirects (previously unescaped for GIF and audio encodes).
* Restrict the protocols ffmpeg/ffprobe may open for each input (`file` for local sources, HTTP(S) for URLs), so crafted playlists or concat files cannot fetch other files or URLs.
* Status endpoints and GraphQL share one redaction allowlist; ffmpeg commands, logs, source paths and raw errors are only returned to admins or `transcoder:debug` schemas. Web responses no longer include the ffmpeg command.
* Fix anonymous access on the front-end controller: status/progress polling works without login again, and `download-file` requires login unless `enableDownloadFileEndpoint` is on.
* Reject path traversal in the progress endpoint.
* Move ffmpeg lock/progress files, watermark rasters and concurrency slot locks from the shared system temp directory into Craft's runtime storage, validate PIDs read from lock files, treat processes owned by another user as running, and only signal PIDs that still belong to an ffmpeg worker.
* Shell- and filesystem-bound settings (`ffmpegPath`, `ffprobePath`, `ffprobeOptions`, encoder presets, default/auto-encode options, output paths/URLs) can only be set in `config/transcoder.php`; CP saves restore them, and validation rejects shell metacharacters.
* The runtime kill switch no longer fails open: if it cannot be read, the last known value is used, and encoding stays paused when no value is known.

### Fixed
* Push GIF jobs with a TTR (`queueTtrSeconds`, default 3600) and measure video/GIF job deadlines from the start of the job, stopping ffmpeg before Craft's worker hard-kills the job. Jobs stop ffmpeg whenever they give up their concurrency slot.
* Wait loops end on `disabled` (and stop ffmpeg) or on untracked `pending` states instead of holding a worker until the timeout; jobs check the kill switch before acquiring a slot or copying a remote source.
* Stalled encodes are terminated instead of only losing their lock file, so a retry cannot start a second ffmpeg for the same output.
* Twig/GraphQL requests no longer start ffmpeg directly for Assets; `getVideoUrl()`/`getGifUrl()` queue the encode so ffmpeg only runs inside the configured concurrency slots.
* GIF output is staged and published atomically, and GIF queue decisions are locked per Asset.
* A completed encode is no longer deleted because of a benign ffmpeg log warning; staged output is only published after ffmpeg succeeds.
* Same-named sources in different volumes (or with `createSubfolders` disabled) no longer share one output: the first Asset owns the canonical file and others fall back to the Asset-ID filename; refresh/repair never deletes another Asset's output.
* Poster jobs no longer overwrite the status, URL or error of a queued/running/finished video encode, and capacity deferrals no longer revert newer poster fields.
* Capacity replacement jobs run ahead of newly queued work, and an exhausted capacity wait records an error instead of leaving the status `queued`.
* Assets in a real `user_123` folder are no longer mistaken for temporary uploads.
* Refreshing a video Asset holds the per-asset queue lock while removing files.
* Retries are classified by exception type rather than translated message text.
* Settings: empty checkbox/table fields no longer cause a TypeError on save; watermark sizes/positions, `subfolderUrlSegment`, encoder presets and output locations are validated.

### Changed
* Schema version 1.3.0 so existing installs run the runtime-settings migration.
* Status lookups use an index of active statuses, check local locks before remote HEAD probes, cache the kill switch briefly, and prune old inactive status files and queue locks.
* Settings and welcome templates are now `_settings.twig` / `_welcome.twig` (the welcome page keeps its `transcoder/welcome` CP route).
* `src/config.php` now matches the model defaults.
* Fork metadata (support links, CODEOWNERS, README) points at this repository; the upstream docs deployment workflow was removed; the CP asset bundle was rebuilt.
* Added `phpstan/phpstan` so `composer phpstan` runs in CI, removed unused `craftcms/rector`, and added a shell-escaping regression harness.

## 5.0.2 - 2026.09.23
### Added
* Add a permission-controlled "Retry missing video/posters" button to the video Asset editor sidebar. Recovery runs asynchronously, preserves generated media and active jobs, and clears inactive error state before checking for missing outputs.

## 5.0.1 - 2026.09.23
### Fixed
* Bound video, poster and GIF capacity waiting with `encodingConcurrencyMaxWaitSeconds` (default one hour) instead of indefinitely creating delayed jobs.
* Distinguish occupied concurrency slots from filesystem locking failures; include native error details and worker UID in lock failures and record busy-slot holder diagnostics.
* Avoid failing a predecessor after a delayed capacity successor has already been queued when status/progress storage fails.
* Invoke local-source encoding callbacks only once when they throw, preventing cache/encoding failures from repeating work inside the same job.

## 5.0.0 - Unreleased
### Added
* Dedicated Craft CMS 5 release line, requiring PHP 8.2+ and the Craft 5 Vite integration.
* Craft 5 integration tests for uploads, queued video/GIF/poster generation, nested Matrix content, GraphQL, settings, and runtime migrations.
* Explicit GraphQL schema permissions for requesting encoding/poster generation (`transcoder:encode`) and diagnostics (`transcoder:debug`). Existing schemas retain read-only status and URL access; grant new permissions only to trusted server-side tokens.

### Fixed
* Skip automatic media inspection immediately when Craft marks an Asset as `resaving`, preventing bulk resaves, propagation work, and upgrades from enqueueing transcoding jobs or reaching media/status checks.
* Resolve Matrix asset owner titles through Craft 5 nested Entries instead of the removed MatrixBlock class.
* Include a volume's filesystem subpath when resolving local source files.
* Correct legacy audio/GIF destination fallback precedence and controller response types; validate crop coordinates before arithmetic.

### Changed
* Register utilities using the native Craft 5 event and expose the concrete settings model to static analysis.
* Keep normal new uploads and explicit replacement/manual transcoding APIs unchanged; existing Asset metadata saves remain inert.
* Preserve both filename strategies and existing output discovery. (The runtime-settings table added in this line is created by `m260519_100000_create_runtime_settings_table`; see 5.0.3 for the schema version bump.)

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
