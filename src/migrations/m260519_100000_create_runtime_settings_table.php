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

use craft\db\Query;
use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\StringHelper;

/**
 * Creates runtime settings for emergency CP Utility switches.
 */
class m260519_100000_create_runtime_settings_table extends Migration
{
    private const TABLE = '{{%transcoder_runtime_settings}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'encodingEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        if (!(new Query())->from(self::TABLE)->exists()) {
            $now = Db::prepareDateForDb(new \DateTime());
            $this->insert(self::TABLE, [
                'encodingEnabled' => true,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);

        return true;
    }
}
