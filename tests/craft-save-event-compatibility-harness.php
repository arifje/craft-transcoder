<?php

declare(strict_types=1);

use craft\elements\Asset;
use craft\events\ModelEvent;
use nystudio107\transcoder\Transcoder;

$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    fwrite(STDERR, "Install Composer dependencies before running this harness.\n");
    exit(1);
}

require $autoloadPath;
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

final class CraftCompatibilityTranscoder extends Transcoder
{
    public int $inspectionCount = 0;

    public function dispatchAssetAfterSave(ModelEvent $event): void
    {
        $this->handleAssetAfterSave($event);
    }

    protected function queueMediaInspectionForUploadedAsset(Asset $asset): void
    {
        $this->inspectionCount++;
    }
}

function craftModelEvent(Asset $asset, bool $isNew): ModelEvent
{
    $event = new ModelEvent();
    $event->sender = $asset;
    $event->isNew = $isNew;

    return $event;
}

function craftAsset(): Asset
{
    return (new ReflectionClass(Asset::class))->newInstanceWithoutConstructor();
}

$plugin = (new ReflectionClass(CraftCompatibilityTranscoder::class))->newInstanceWithoutConstructor();

$bulkResave = craftAsset();
$bulkResave->resaving = true;
$plugin->dispatchAssetAfterSave(craftModelEvent($bulkResave, true));
if ($plugin->inspectionCount !== 0) {
    fwrite(STDERR, "Craft bulk resave reached automatic media inspection.\n");
    exit(1);
}

$newUpload = craftAsset();
$plugin->dispatchAssetAfterSave(craftModelEvent($newUpload, true));
if ($plugin->inspectionCount !== 1) {
    fwrite(STDERR, "Craft new-Asset upload did not reach automatic media inspection.\n");
    exit(1);
}

$craftVersion = Composer\InstalledVersions::getPrettyVersion('craftcms/cms') ?? 'unknown';
fwrite(STDOUT, 'Craft ' . $craftVersion . " save-event compatibility harness passed\n");
