<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;

/**
 * Install/uninstall migration for Transcoder.
 */
class Install extends Migration
{
    private const RUNTIME_SETTINGS_TABLE = '{{%transcoder_runtime_settings}}';
    private const VIDEO_SOURCES_TABLE = '{{%transcoder_video_sources}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createRuntimeSettingsTable();
        $this->createVideoSourcesTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::VIDEO_SOURCES_TABLE);
        $this->dropTableIfExists(self::RUNTIME_SETTINGS_TABLE);

        return true;
    }

    /**
     * Create the runtime settings table used by the CP Utility kill switch.
     */
    private function createRuntimeSettingsTable(): void
    {
        if ($this->db->tableExists(self::RUNTIME_SETTINGS_TABLE)) {
            return;
        }

        $this->createTable(self::RUNTIME_SETTINGS_TABLE, [
            'id' => $this->primaryKey(),
            'encodingEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        if ((new Query())->from(self::RUNTIME_SETTINGS_TABLE)->exists()) {
            return;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $this->insert(self::RUNTIME_SETTINGS_TABLE, [
            'encodingEnabled' => true,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);
    }

    /**
     * Create shared source-generation state for replaced video assets.
     */
    private function createVideoSourcesTable(): void
    {
        if ($this->db->tableExists(self::VIDEO_SOURCES_TABLE)) {
            return;
        }

        $this->createTable(self::VIDEO_SOURCES_TABLE, [
            'assetId' => $this->integer()->notNull(),
            'sourceGeneration' => $this->string(32)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->addPrimaryKey(null, self::VIDEO_SOURCES_TABLE, ['assetId']);
        $this->createIndex(null, self::VIDEO_SOURCES_TABLE, ['sourceGeneration']);
        $this->addForeignKey(null, self::VIDEO_SOURCES_TABLE, ['assetId'], '{{%elements}}', ['id'], 'CASCADE');
    }
}
