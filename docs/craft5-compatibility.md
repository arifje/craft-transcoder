# Craft 5 Compatibility Audit

Branch: `skoften-codex-v5`. Prepared version: **5.0.0**, not yet published.

This release line requires Craft CMS 5 and PHP 8.2+. Keep the 4.x plugin release line for Craft 4 installations. The audit follows [Craft's plugin upgrade guide](https://craftcms.com/docs/5.x/extend/updating-plugins.html) and checks the installed Craft source, not just dependency constraints.

## Findings and Changes

| Area | Result |
| --- | --- |
| Dependencies | Require Craft `^5.0.0`, Plugin Vite `^5.0.0`, and PHP `^8.2`. Resolve development dependencies against PHP 8.2. |
| Save events | One Asset after-save listener. Reject `resaving` before queries or service work. Only newly created Assets enqueue inspection; no automatic Entry scanning. |
| Matrix | Replace the removed `MatrixBlock` lookup with nested Entry queries and follow the owner chain to the containing article. Explicit field scanning works with per-layout overridden field handles. |
| Source paths | Include the volume's filesystem subpath when composing a local Asset source path. Tested with a volume sharing a filesystem via a subpath. |
| Controllers | Match the Yii response type returned by Craft's JSON and redirect helpers. Preserve controller access and utility permission checks. |
| Settings and utilities | Expose the concrete settings model; use Craft 5's utility-registration event. Settings and utility templates render with Craft 5 forms. |
| Queue | Existing Craft queue APIs, serialized jobs, retries and concurrency pools remain. Upload requests enqueue inspection; source and output checks run there. |
| GraphQL | Actual Craft AssetInterface fields and resolvers work. Encoding and diagnostics now require explicit schema permissions. Read-only responses exclude internal diagnostics, including in `rawJson`. |
| Output naming | Neither naming strategy is changed. Source filenames remain source filenames; legacy candidate lookup is retained. |
| Legacy helpers | Correct concatenation/null-fallback precedence for audio and legacy GIF output paths. Validate crop coordinates before arithmetic. |
| Database | Runtime settings migration remains idempotent. Native install/uninstall work. Schema stays `1.2.0`; no new migration, manifest, or source-generation table. |
| Tooling | Full PHPStan passes without an error baseline. Existing regression harnesses and a new database/FFmpeg integration suite run on Craft 5. |

## GraphQL Permissions

In each trusted token's Craft GraphQL schema, review the **Transcoder** permissions:

- `transcoder:encode`: permits `queueIfMissing: true` and poster `generate: true`.
- `transcoder:debug`: permits `includeDebug: true` and detailed diagnostic/error payloads.

These are opt-in. Existing schemas can still query normal status, progress, filenames, URLs, and poster URLs. Requests for a restricted action return a GraphQL error without starting it. Without diagnostic permission, errors are generic and `rawJson` contains only public status fields.

Only grant these permissions to trusted server-side integrations. Encoding options influence FFmpeg commands, and diagnostics can contain server paths. Do not give these privileges to public/browser tokens. The runtime kill switch and encoding-server restrictions still apply. Twig helper behavior is unchanged.

## Upgrade Checklist

1. Back up the database, project config, assets, and generated output. Upgrade a staging copy of the site first, following Craft's own upgrade instructions.
2. Use the Craft 5 plugin branch with Craft 5 dependencies. Do not install it on Craft 4. Check compatibility of the site's other plugins separately, particularly upload/import and asset-renaming plugins.
3. Keep the existing `transcoder.php` configuration, filename strategy, paths, URLs, and runtime-storage arrangement. This update does not require deleting existing encodes or status files.
4. Pause workers during deployment, update Composer dependencies, run `php craft up`, then restart workers so no old PHP process retains the Craft 4 classes.
5. Review GraphQL permissions for trusted automation tokens. Public status queries need no new permissions.
6. Verify one CP upload and one Make/GraphQL import, including the final source folder and generated output. Test both an existing archived video and a new video through each frontend backend.
7. Verify the runtime utility with `allowAdminChanges=false`, remote/NFS source fallback, watermarking, signed URLs, and the frontend's admin-only debug display using the site's real configuration.

Metadata saves and Entry saves remain inert, as in the upload-inspection release line. Deliberate video replacements should continue to call `refreshVideoAsset()` after Craft saves the new file; the upgrade does not add an implicit replacement or Entry-save listener. Explicit manual APIs and the repair console command remain available.

## Validation Performed

Validated locally using **Craft 5.11.1**, **Plugin Vite 5.0.3**, **PHP 8.2.33**, **MariaDB 10.11**, and **FFmpeg 7.1.5**, in disposable containers and a test-only database.

- All PHP source/test files pass syntax checks.
- `composer phpstan`: passes across the complete plugin.
- `composer test`: all five regression harnesses pass, including both naming strategies, output locations, resaving, and concurrency slots.
- `composer test:craft5`: real Asset upload/save events, duplicate inspection callbacks, video/GIF/poster job creation, real FFmpeg job execution with black-bar cropping and PNG watermarking, short-video poster timestamps, audio output, nested Matrix relations, overridden field handles, Entry/bulk saves, GraphQL reads/permissions, rendered settings/utility templates, bounded retries, and migration teardown/recreation pass.
- Native `plugin/install transcoder` and `plugin/uninstall transcoder` commands pass.
- Craft's Craft-5 Rector ruleset dry run reports no remaining changes.
- Composer audit reports no dependency security advisories. `composer --no-plugins validate --no-check-publish` passes with the repository's existing explicit-version warning. Plain validation hits an upstream plugin-installer relative-path error under Composer 2.10; CI disables plugins only for metadata validation.
- Full `composer check-cs` still reports pre-existing formatting differences in five legacy files. Touched controllers, GraphQL code, plugin bootstrap, and new tests pass scoped ECS checks. CI keeps the full formatting audit visible but advisory to avoid mixing a whole-service whitespace rewrite into the compatibility changes.

The new CI matrix targets PHP 8.2 and 8.4; remote CI has not run until the branch is pushed. This is not a claim that every Craft 5 minor version, every FFmpeg codec/build, third-party upload integration, production NFS topology, or live upgrade from a production database has been exercised. Those remain staging acceptance checks.

## Running the Tests

Run `composer update`, `composer test`, and `composer phpstan` in this repository.

For integration tests, use a disposable MySQL/MariaDB database named **transcoder_test**. Never use a production database. The suite creates assets/fields/entries, clears the test queue, and creates/drops Transcoder's runtime table. It rejects a different database name and DSN/URL overrides. Configure only the discrete Craft database environment variables:

```sh
export TRANSCODER_TESTING=1
export CRAFT_DB_DRIVER=mysql
export CRAFT_DB_SERVER=127.0.0.1
export CRAFT_DB_PORT=3306
export CRAFT_DB_DATABASE=transcoder_test
export CRAFT_DB_USER=your_test_database_user
export CRAFT_DB_PASSWORD=your_test_database_password
php tests/craft5/craft.php install/craft --interactive=0 --username=admin --email=admin@transcoder.test --password=IsolatedTest12345 --site-name=TranscoderTest --site-url=https://transcoder.test --language=en
composer test:craft5
```

PHP needs Craft's required extensions; `ffmpeg` and `ffprobe` must be available at `/usr/bin`. Containerized tests need an init/reaper (for example Docker `--init`) because the encoder launches background processes. Test runtime/media files stay under the system temporary directory's `transcoder-craft5-test` folder.
