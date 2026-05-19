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
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use Throwable;
use yii\base\Component;

/**
 * Runtime settings that admins can toggle outside project config.
 */
class RuntimeSettingsService extends Component
{
    private const TABLE = '{{%transcoder_runtime_settings}}';

    /**
     * Return whether Transcoder may start ffmpeg work.
     *
     * @return bool
     */
    public function isEncodingEnabled(): bool
    {
        try {
            $value = (new Query())
                ->select(['encodingEnabled'])
                ->from(self::TABLE)
                ->scalar();
        } catch (Throwable $e) {
            Craft::warning('Transcoder: Could not read runtime settings: ' . $e->getMessage(), __METHOD__);
            return true;
        }

        return $value === false ? true : (bool)$value;
    }

    /**
     * Set whether Transcoder may start ffmpeg work.
     *
     * @param bool $enabled
     * @return void
     */
    public function setEncodingEnabled(bool $enabled): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $db = Craft::$app->getDb();
        $exists = (new Query())
            ->from(self::TABLE)
            ->exists();

        if ($exists) {
            $db->createCommand()
                ->update(self::TABLE, [
                    'encodingEnabled' => $enabled,
                    'dateUpdated' => $now,
                ])
                ->execute();
            return;
        }

        $db->createCommand()
            ->insert(self::TABLE, [
                'encodingEnabled' => $enabled,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])
            ->execute();
    }
}
