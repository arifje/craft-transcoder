<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\db\EntryQuery;
use craft\helpers\Console;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use nystudio107\transcoder\Transcoder;
use Throwable;
use yii\console\ExitCode;

/**
 * Inspect and repair generated video output for an explicit Entry scope.
 */
class VideosController extends Controller
{
    /**
     * @var string|null Inclusive Entry creation date in YYYY-MM-DD format.
     */
    public ?string $from = null;

    /**
     * @var string|null Inclusive end of the Entry creation-date range in YYYY-MM-DD format.
     */
    public ?string $to = null;

    /**
     * @var string|null One Entry ID or a comma-separated list of Entry IDs.
     */
    public ?string $entryId = null;

    /**
     * @var bool Preview the complete repair plan without changing files or queueing jobs.
     */
    public bool $dryRun = false;

    /**
     * @var bool Remove unrecognized video files from selected Entry-ID output directories.
     */
    public bool $cleanOrphans = false;

    /**
     * @inheritdoc
     */
    public $defaultAction = 'repair';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'from',
            'to',
            'entryId',
            'dryRun',
            'cleanOrphans',
        ]);
    }

    /**
     * Scan scoped Entries, remove recognized alternate Transcoder output, and
     * queue missing canonical videos/posters after explicit confirmation.
     *
     * Examples:
     *   php craft transcoder/videos/repair --from=2026-08-17
     *   php craft transcoder/videos/repair --from=2026-08-17 --to=2026-08-31
     *   php craft transcoder/videos/repair --entry-id=4958309
     */
    public function actionRepair(): int
    {
        try {
            $scope = $this->resolveScope();
        } catch (Throwable $e) {
            $this->failure($e->getMessage());
            $this->printExamples();
            return ExitCode::USAGE;
        }

        $settings = Transcoder::$plugin->getSettings();
        if (!Transcoder::$plugin->transcode->isRuntimeEncodingEnabled()) {
            $this->failure('Runtime encoding is disabled in the Transcoder utility. No changes were made.');
            return ExitCode::CONFIG;
        }
        if (!$settings->enableVideoEncoding && !$settings->enableVideoPosters) {
            $this->failure('Video encoding and video poster generation are both disabled. No changes were made.');
            return ExitCode::CONFIG;
        }

        $this->stdout("\nTranscoder video repair\n", Console::BOLD);
        $this->stdout('Scope: ' . $scope['description'] . "\n");
        $this->stdout('Filename strategy: ' . ($settings->videoFilenameStrategy ?: 'source') . "\n\n");
        $this->stdout("This command will:\n", Console::FG_YELLOW);
        $this->stdout("  1. Recursively inspect video fields for only the selected Entries.\n");
        $this->stdout("  2. Keep valid output with the current canonical filename.\n");
        $this->stdout("  3. Remove recognized alternate filenames, invalid output, and stale Transcoder status files for those Assets.\n");
        if ($this->cleanOrphans) {
            $this->stdout("     Entry-ID output directories are also checked for orphan video files that are not canonical for any selected Asset.\n");
        }
        $this->stdout("  4. Queue normal EncodeVideo and GenerateVideoPosters jobs where canonical output is missing.\n");
        $this->stdout("Original Craft Assets are never deleted or modified. Encoding continues asynchronously in Craft's queue.\n\n");

        $entries = $scope['query']->all();
        $entryIds = [];
        $assets = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof Entry || !$entry->id) {
                continue;
            }
            $entryIds[(int)$entry->id] = true;
            foreach (Transcoder::$plugin->transcode->getVideoAssetsForElement($entry) as $asset) {
                if (!$asset->id) {
                    continue;
                }
                $assetId = (int)$asset->id;
                $assets[$assetId]['asset'] = $asset;
                $assets[$assetId]['entries'][(int)$entry->id] = trim((string)$entry->title);
            }
        }
        ksort($assets);

        $plans = [];
        $planErrors = [];
        foreach ($assets as $assetId => $context) {
            try {
                $plans[$assetId] = [
                    'plan' => Transcoder::$plugin->transcode->getVideoAssetRepairPlan($context['asset']),
                    'entries' => $context['entries'],
                ];
            } catch (Throwable $e) {
                $planErrors[$assetId] = $e->getMessage();
            }
        }

        $this->stdout('Found ' . count($entryIds) . ' Entry/Entries and ' . count($assets) . " unique video Asset(s).\n\n");
        $totals = $this->printPlans($plans, $planErrors);
        $orphanFiles = [];
        if ($this->cleanOrphans && !empty($planErrors)) {
            $this->stdout("Orphan cleanup was skipped because one or more Assets could not be inspected.\n\n", Console::FG_YELLOW);
        } else {
            $orphanFiles = $this->getEntryOwnedOrphanVideoFiles($plans);
        }
        if (!empty($orphanFiles)) {
            $this->stdout("Orphan video output in selected Entry directories\n", Console::BOLD);
            foreach ($orphanFiles as $file) {
                $this->stdout('  Remove [orphan video filename]: ' . $file['path']
                    . ($file['size'] > 0 ? ' (' . $this->formatBytes($file['size']) . ')' : '')
                    . "\n", Console::FG_RED);
            }
            $this->stdout("\n");
            $totals['cleanupFiles'] += count($orphanFiles);
        }

        if ($totals['actionable'] === 0 && empty($orphanFiles)) {
            if (!empty($planErrors)) {
                $this->failure('No changes were applied because one or more video Assets could not be inspected.');
                return ExitCode::DATAERR;
            }
            if ($totals['active'] > 0) {
                $this->stdout("No changes were applied; active video Assets were skipped. Run the command again after their jobs finish.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }
            $this->success('No missing canonical videos, missing posters, or recognized alternate output were found.');
            return ExitCode::OK;
        }

        $this->stdout("\nPlanned changes\n", Console::BOLD);
        $this->stdout('  Assets to repair: ' . $totals['actionable'] . "\n");
        $this->stdout('  Files to remove: ' . $totals['cleanupFiles'] . "\n");
        $this->stdout('  Missing video encodes to queue: ' . $totals['queueVideo'] . "\n");
        $this->stdout('  Assets with missing posters to queue: ' . $totals['queuePosters'] . "\n");
        if ($totals['active'] > 0) {
            $this->stdout('  Active Assets skipped: ' . $totals['active'] . "\n", Console::FG_YELLOW);
        }

        if ($this->dryRun) {
            $this->stdout("Dry run complete. No files were removed and no jobs were queued.\n", Console::FG_CYAN);
            return empty($planErrors) ? ExitCode::OK : ExitCode::DATAERR;
        }

        if (!$this->interactive) {
            $this->failure('Interactive confirmation is required. Re-run without --interactive=0, or use --dry-run=1 to preview.');
            return ExitCode::USAGE;
        }

        $this->stdout("\nReview the file paths above carefully.\n", Console::FG_YELLOW);
        if (!$this->confirm('Apply this repair plan?', false)) {
            $this->stdout("Cancelled. No files were removed and no jobs were queued.\n", Console::FG_CYAN);
            return ExitCode::OK;
        }

        $removedFiles = 0;
        $queuedAssets = 0;
        $failures = 0;
        if (!empty($orphanFiles)) {
            try {
                $removedFiles += Transcoder::$plugin->transcode->removeVideoRepairFiles($orphanFiles);
            } catch (Throwable $e) {
                $failures++;
                $this->stderr('Failed to clean orphan video output: ' . $e->getMessage() . "\n", Console::FG_RED);
            }
        }
        foreach ($plans as $assetId => $context) {
            if (!$context['plan']['actionable']) {
                continue;
            }

            try {
                $asset = Asset::find()->id($assetId)->one();
                if (!$asset instanceof Asset) {
                    throw new \RuntimeException('Asset no longer exists.');
                }
                $result = Transcoder::$plugin->transcode->repairVideoAsset($asset);
                $removedFiles += $result['removedFiles'];
                if ($result['queued']) {
                    $queuedAssets++;
                }
                $this->stdout('Repaired video Asset #' . $assetId
                    . ': removed ' . $result['removedFiles']
                    . ' file(s), status ' . ($result['status']['status'] ?? 'clean') . ".\n");
            } catch (Throwable $e) {
                $failures++;
                $this->stderr('Failed video Asset #' . $assetId . ': ' . $e->getMessage() . "\n", Console::FG_RED);
            }
        }

        $this->stdout("\n");
        if ($failures > 0 || !empty($planErrors)) {
            $this->failure('Repair finished with errors. Removed ' . $removedFiles . ' file(s) and queued work for ' . $queuedAssets . ' Asset(s).');
            return ExitCode::DATAERR;
        }

        $this->success('Repair complete. Removed ' . $removedFiles . ' file(s) and queued work for ' . $queuedAssets . ' Asset(s).');
        return ExitCode::OK;
    }

    /**
     * @return array{query: EntryQuery, description: string}
     */
    private function resolveScope(): array
    {
        $hasEntryIds = trim((string)$this->entryId) !== '';
        $hasDateScope = trim((string)$this->from) !== '' || trim((string)$this->to) !== '';
        if ($hasEntryIds && $hasDateScope) {
            throw new \InvalidArgumentException('Choose either --entry-id or a --from/--to date scope, not both.');
        }
        if (!$hasEntryIds && !$hasDateScope) {
            throw new \InvalidArgumentException('A bounded scope is required.');
        }
        if (!$hasEntryIds && trim((string)$this->from) === '') {
            throw new \InvalidArgumentException('--to requires --from.');
        }

        $query = Entry::find()
            ->site('*')
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->orderBy(['elements.id' => SORT_ASC]);

        if ($hasEntryIds) {
            $ids = [];
            foreach (explode(',', (string)$this->entryId) as $rawId) {
                $rawId = trim($rawId);
                if ($rawId === '' || !ctype_digit($rawId) || (int)$rawId <= 0) {
                    throw new \InvalidArgumentException('--entry-id must contain one or more positive numeric IDs.');
                }
                $ids[] = (int)$rawId;
            }
            $ids = array_values(array_unique($ids));
            if (empty($ids)) {
                throw new \InvalidArgumentException('--entry-id must contain one or more positive numeric IDs.');
            }

            $query->id($ids);
            return [
                'query' => $query,
                'description' => 'Entry ID' . (count($ids) === 1 ? ' ' : 's ') . implode(', ', $ids),
            ];
        }

        $from = $this->parseDate((string)$this->from, '--from');
        $dateCondition = '>= ' . $from->format(DateTimeInterface::ATOM);
        $description = 'Entries created on or after ' . $from->format('Y-m-d');
        if (trim((string)$this->to) !== '') {
            $to = $this->parseDate((string)$this->to, '--to');
            if ($to < $from) {
                throw new \InvalidArgumentException('--to must be the same as or later than --from.');
            }
            $exclusiveEnd = $to->modify('+1 day');
            $dateCondition = [
                'and',
                '>= ' . $from->format(DateTimeInterface::ATOM),
                '< ' . $exclusiveEnd->format(DateTimeInterface::ATOM),
            ];
            $description = 'Entries created from ' . $from->format('Y-m-d') . ' through ' . $to->format('Y-m-d');
        }

        $query->dateCreated($dateCondition);
        return [
            'query' => $query,
            'description' => $description,
        ];
    }

    private function parseDate(string $value, string $option): DateTimeImmutable
    {
        $timezone = new DateTimeZone(Craft::$app->getTimeZone());
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date
            || $date->format('Y-m-d') !== trim($value)
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new \InvalidArgumentException($option . ' must use YYYY-MM-DD format.');
        }

        return $date;
    }

    /**
     * @param array<int, array{plan: array, entries: array<int, string>}> $plans
     * @param array<int, string> $errors
     * @return array{actionable: int, cleanupFiles: int, queueVideo: int, queuePosters: int, active: int}
     */
    private function printPlans(array $plans, array $errors): array
    {
        $totals = [
            'actionable' => 0,
            'cleanupFiles' => 0,
            'queueVideo' => 0,
            'queuePosters' => 0,
            'active' => 0,
        ];

        foreach ($plans as $assetId => $context) {
            $plan = $context['plan'];
            if ($plan['active']) {
                $totals['active']++;
            }
            if (!$plan['actionable'] && !$plan['active']) {
                continue;
            }

            $entryLabels = [];
            foreach ($context['entries'] as $entryId => $title) {
                $entryLabels[] = '#' . $entryId . ($title !== '' ? ' ' . $title : '');
            }
            $this->stdout('Asset #' . $assetId . ' ' . $plan['filename'] . "\n", Console::BOLD);
            $this->stdout('  Entries: ' . implode('; ', $entryLabels) . "\n");
            $this->stdout('  Source: ' . ($plan['sourceAvailable'] ? 'available' : 'not currently available') . "\n");
            $this->stdout('  Canonical video: ' . $plan['canonicalVideo']['path'] . "\n");
            $this->stdout('  Canonical state: ' . ($plan['canonicalVideo']['valid']
                ? 'valid (' . $this->formatBytes($plan['canonicalVideo']['size']) . ')'
                : 'missing or invalid') . "\n");

            if ($plan['active']) {
                $this->stdout("  Action: skipped because encoding/poster work is active.\n\n", Console::FG_YELLOW);
                continue;
            }

            if ($plan['queueVideo']) {
                $this->stdout("  Action: queue canonical video encode.\n", Console::FG_YELLOW);
            }
            if ($plan['queuePosters']) {
                $this->stdout('  Action: queue missing posters: ' . implode(', ', $plan['missingPosterHandles']) . ".\n", Console::FG_YELLOW);
            }
            foreach ($plan['cleanupFiles'] as $file) {
                $this->stdout('  Remove [' . $file['reason'] . ']: ' . $file['path']
                    . ($file['size'] > 0 ? ' (' . $this->formatBytes($file['size']) . ')' : '')
                    . "\n", Console::FG_RED);
            }
            $this->stdout("\n");

            $totals['actionable']++;
            $totals['cleanupFiles'] += count($plan['cleanupFiles']);
            $totals['queueVideo'] += $plan['queueVideo'] ? 1 : 0;
            $totals['queuePosters'] += $plan['queuePosters'] ? 1 : 0;
        }

        foreach ($errors as $assetId => $message) {
            $this->stderr('Could not inspect video Asset #' . $assetId . ': ' . $message . "\n", Console::FG_RED);
        }

        return $totals;
    }

    /**
     * Find generated video files that cannot be canonical for any selected Asset.
     *
     * The broader sweep is limited to output directories whose final path
     * segment exactly matches one of the selected Entry IDs. Shared/root output
     * directories are never swept.
     *
     * @param array<int, array{plan: array, entries: array<int, string>}> $plans
     * @return array<int, array{path: string, kind: string, reason: string, size: int}>
     */
    private function getEntryOwnedOrphanVideoFiles(array $plans): array
    {
        $settings = Transcoder::$plugin->getSettings();
        if (!$this->cleanOrphans || !$settings->enableVideoEncoding) {
            return [];
        }

        $knownPaths = [];
        $directories = [];
        $blockedDirectories = [];
        foreach ($plans as $context) {
            $plan = $context['plan'];
            $canonicalPath = $plan['canonicalVideo']['path'];
            $knownPaths[$canonicalPath] = true;
            foreach ($plan['cleanupFiles'] as $file) {
                $knownPaths[$file['path']] = true;
            }
            $directory = dirname($canonicalPath);
            if ($plan['active']) {
                $blockedDirectories[$directory] = true;
                continue;
            }

            $directoryName = basename($directory);
            foreach (array_keys($context['entries']) as $entryId) {
                if ($directoryName === (string)$entryId) {
                    $directories[$directory] = true;
                    break;
                }
            }
        }

        foreach (array_keys($blockedDirectories) as $directory) {
            unset($directories[$directory]);
        }

        $suffixes = [];
        foreach ($settings->videoEncoders as $encoder) {
            $suffix = strtolower((string)($encoder['fileSuffix'] ?? ''));
            if ($suffix !== '') {
                $suffixes[$suffix] = true;
            }
        }

        $files = [];
        foreach (array_keys($directories) as $directory) {
            $entries = @scandir($directory);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (isset($knownPaths[$path]) || (!is_file($path) && !is_link($path))) {
                    continue;
                }
                $suffix = '.' . strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (!isset($suffixes[$suffix])) {
                    continue;
                }
                $files[$path] = [
                    'path' => $path,
                    'kind' => 'output',
                    'reason' => 'orphan video filename',
                    'size' => is_file($path) ? max(0, (int)@filesize($path)) : 0,
                ];
            }
        }

        return array_values($files);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KiB';
        }

        return number_format($bytes / (1024 * 1024), 1) . ' MiB';
    }

    private function printExamples(): void
    {
        $this->stdout("Examples:\n");
        $this->stdout("  php craft transcoder/videos/repair --from=2026-08-17\n");
        $this->stdout("  php craft transcoder/videos/repair --from=2026-08-17 --to=2026-08-31\n");
        $this->stdout("  php craft transcoder/videos/repair --entry-id=4958309\n");
        $this->stdout("  php craft transcoder/videos/repair --entry-id=4958309,4958310 --dry-run=1\n");
        $this->stdout("  php craft transcoder/videos/repair --entry-id=4958309 --clean-orphans=1\n");
    }
}
