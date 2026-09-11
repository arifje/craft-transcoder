<?php

declare(strict_types=1);

namespace craft\base {
    class Model
    {
    }

    class Plugin
    {
    }
}

namespace craft\elements {
    class Asset
    {
        public const EVENT_AFTER_SAVE = 'afterSave';

        public bool $resaving = false;
    }
}

namespace craft\events {
    class ModelEvent
    {
        public function __construct(
            public mixed $sender,
            public bool $isNew,
        ) {
        }
    }
}

namespace nystudio107\transcoder\services {
    trait ServicesTrait
    {
    }
}

namespace {
    use craft\elements\Asset;
    use craft\events\ModelEvent;
    use nystudio107\transcoder\Transcoder;

    require dirname(__DIR__) . '/src/Transcoder.php';

    final class TestableTranscoder extends Transcoder
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

    function assertInspectionCount(string $label, int $expected, TestableTranscoder $plugin): void
    {
        if ($plugin->inspectionCount !== $expected) {
            fwrite(
                STDERR,
                sprintf(
                    "%s failed. Expected %d inspection(s), got %d.\n",
                    $label,
                    $expected,
                    $plugin->inspectionCount,
                ),
            );
            exit(1);
        }
    }

    $plugin = new TestableTranscoder();

    $bulkResave = new Asset();
    $bulkResave->resaving = true;
    $plugin->dispatchAssetAfterSave(new ModelEvent($bulkResave, true));
    assertInspectionCount('new Asset during a bulk resave', 0, $plugin);

    $propagatedBulkResave = new Asset();
    $propagatedBulkResave->resaving = true;
    $plugin->dispatchAssetAfterSave(new ModelEvent($propagatedBulkResave, false));
    assertInspectionCount('existing Asset during a propagated bulk resave', 0, $plugin);

    $metadataSave = new Asset();
    $plugin->dispatchAssetAfterSave(new ModelEvent($metadataSave, false));
    assertInspectionCount('existing Asset metadata save', 0, $plugin);

    $newUpload = new Asset();
    $plugin->dispatchAssetAfterSave(new ModelEvent($newUpload, true));
    assertInspectionCount('normal new Asset upload', 1, $plugin);

    fwrite(STDOUT, "asset save resaving harness passed\n");
}
