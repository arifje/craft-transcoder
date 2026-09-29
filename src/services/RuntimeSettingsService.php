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
     * Seconds a read value is reused, so status polling does not query the
     * database on every call while long-running jobs still notice changes.
     */
    private const CACHE_SECONDS = 5;

    private ?bool $encodingEnabled = null;

    private float $encodingEnabledReadAt = 0.0;

    /**
     * Return whether Transcoder may start ffmpeg work.
     *
     * If the setting cannot be read, the last known value is reused. Without a
     * known value encoding stays off, so the kill switch never fails open.
     *
     * @return bool
     */
    public function isEncodingEnabled(): bool
    {
        if ($this->encodingEnabled !== null && (microtime(true) - $this->encodingEnabledReadAt) < self::CACHE_SECONDS) {
            return $this->encodingEnabled;
        }

        try {
            $value = (new Query())
                ->select(['encodingEnabled'])
                ->from(self::TABLE)
                ->scalar();
        } catch (Throwable $e) {
            Craft::error('Transcoder: Could not read runtime settings; '
                . ($this->encodingEnabled === null ? 'encoding is paused until they can be read' : 'using the last known value')
                . '. Run pending migrations if the transcoder_runtime_settings table is missing. ' . $e->getMessage(), __METHOD__);

            return $this->encodingEnabled ?? false;
        }

        $this->encodingEnabled = $value === false ? true : (bool)$value;
        $this->encodingEnabledReadAt = microtime(true);

        return $this->encodingEnabled;
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
            $this->encodingEnabled = $enabled;
            $this->encodingEnabledReadAt = microtime(true);
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
        $this->encodingEnabled = $enabled;
        $this->encodingEnabledReadAt = microtime(true);
    }
}
