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
 * Keeps the 4.4.41 migration name available without creating replacement state.
 */
class m260813_100000_create_video_sources_table extends Migration
{
    private const TABLE = '{{%transcoder_video_sources}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
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
