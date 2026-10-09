<?php

namespace justinholtweb\yo\migrations;

use craft\db\Migration;
use justinholtweb\yo\db\Table;
use justinholtweb\yo\services\Digest;
use justinholtweb\yo\services\Email;

/**
 * Adds the email channel's digest queue and the digest's "last sent" marker.
 */
class m261009_000000_email_webhook_channels extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::EMAIL_QUEUE)) {
            Email::createQueueTable($this);
        }

        if (!$this->db->tableExists(Table::DIGESTS)) {
            Digest::createTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::EMAIL_QUEUE);
        $this->dropTableIfExists(Table::DIGESTS);

        return true;
    }
}
