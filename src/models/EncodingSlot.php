<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\models;

/**
 * A process-local handle for one acquired FFmpeg concurrency slot.
 */
final class EncodingSlot
{
    /**
     * @param resource|null $handle
     */
    public function __construct(
        private mixed $handle,
        public int $number,
        public string $pool,
    ) {
    }

    /**
     * Release this slot so another queue process can acquire it.
     */
    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        ftruncate($this->handle, 0);
        fflush($this->handle);
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
