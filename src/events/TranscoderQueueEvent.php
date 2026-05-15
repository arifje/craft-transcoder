<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Event emitted before a video asset is queued for encoding.
 */
class TranscoderQueueEvent extends Event
{
    /**
     * @var ElementInterface|null The element whose save triggered the encode.
     */
    public ?ElementInterface $element = null;

    /**
     * @var int|null The video asset ID.
     */
    public ?int $assetId = null;

    /**
     * @var array Video options used for the encode.
     */
    public array $videoOptions = [];

    /**
     * @var array Extra encoding options, such as watermark metadata.
     */
    public array $encodingOptions = [];

    /**
     * @var bool Whether the encode should be queued.
     */
    public bool $isValid = true;
}
