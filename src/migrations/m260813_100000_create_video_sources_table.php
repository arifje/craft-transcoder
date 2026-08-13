<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\migrations;

use craft\db\Migration;

/**
 * Stores shared replacement generations for video assets.
 */
class m260813_100000_create_video_sources_table extends Migration
{
    private const TABLE = '{{%transcoder_video_sources}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'assetId' => $this->integer()->notNull(),
            'sourceGeneration' => $this->string(32)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->addPrimaryKey(null, self::TABLE, ['assetId']);
        $this->createIndex(null, self::TABLE, ['sourceGeneration']);
        $this->addForeignKey(null, self::TABLE, ['assetId'], '{{%elements}}', ['id'], 'CASCADE');

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
