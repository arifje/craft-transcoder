<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\utilities;

use Craft;
use craft\base\Utility;
use nystudio107\transcoder\Transcoder;

/**
 * CP Utility for runtime encoding controls.
 */
class EncodingUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('transcoder', 'Transcoder Encoding');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'transcoder-encoding';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return dirname(__DIR__) . '/icon.svg';
    }

    /**
     * @inheritdoc
     */
    public static function iconPath(): ?string
    {
        return self::icon();
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('transcoder/_utility', [
            'enabled' => Transcoder::$plugin->runtimeSettings->isEncodingEnabled(),
        ]);
    }
}
