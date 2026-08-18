<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\services;

use Craft;
use craft\helpers\FileHelper;
use nystudio107\transcoder\models\EncodingSlot;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Limits active FFmpeg work independently on each encoding server.
 */
class EncodingConcurrencyService extends Component
{
    /**
     * Try to acquire one slot without blocking the queue worker.
     */
    public function acquire(string $pool, int $limit, string $jobType, ?int $assetId = null): ?EncodingSlot
    {
        $pool = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($pool)) ?: 'default';
        $limit = max(1, $limit);
        $directory = $this->getLockDirectory();

        if (!is_dir($directory)) {
            try {
                FileHelper::createDirectory($directory);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Transcoder could not create its concurrency lock directory: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        for ($slotNumber = 1; $slotNumber <= $limit; $slotNumber++) {
            $path = $directory . DIRECTORY_SEPARATOR . $pool . '-' . $slotNumber . '.lock';
            $handle = @fopen($path, 'c+');
            if (!is_resource($handle)) {
                throw new RuntimeException('Transcoder could not open concurrency lock file: ' . $path);
            }

            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                continue;
            }

            $metadata = json_encode([
                'pool' => $pool,
                'slot' => $slotNumber,
                'jobType' => $jobType,
                'assetId' => $assetId,
                'pid' => getmypid(),
                'acquiredAt' => time(),
            ], JSON_UNESCAPED_SLASHES);
            if (is_string($metadata)) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $metadata);
                fflush($handle);
            }

            Craft::info(sprintf(
                'Transcoder acquired %s FFmpeg slot %d/%d for %s asset #%s',
                $pool,
                $slotNumber,
                $limit,
                $jobType,
                $assetId ?? 'unknown'
            ), __METHOD__);

            return new EncodingSlot($handle, $slotNumber, $pool);
        }

        return null;
    }

    /**
     * Local runtime locks protect each encoding host independently.
     */
    protected function getLockDirectory(): string
    {
        $installationKey = substr(sha1(Craft::$app->getBasePath()), 0, 12);

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'craft-transcoder-' . $installationKey;
    }
}
