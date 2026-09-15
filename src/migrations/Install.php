<?php

namespace justinholtweb\yo\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\yo\db\Table;

/**
 * Yo install migration.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Deliveries point at messages, so they go first.
        $this->dropTableIfExists(Table::DELIVERIES);
        $this->dropTableIfExists(Table::MESSAGES);

        return true;
    }

    private function createTables(): void
    {
        // Only messages that outlive the request land here. A message for whoever is making this
        // request rides in the session and never costs a write — which is the whole reason a
        // plugin can call Yo on every save without anyone regretting it.
        $this->createTable(Table::MESSAGES, [
            'id' => $this->primaryKey(),
            'plugin' => $this->string(64)->notNull()->defaultValue(''),
            'title' => $this->string(255)->notNull(),
            'body' => $this->text(),
            'type' => $this->string(64)->notNull()->defaultValue('notice'),
            'audience' => $this->string(64)->notNull()->defaultValue('everyone'),
            'channels' => $this->text(),
            'actions' => $this->text(),
            // Null means "whatever the type says", not false. The distinction is load-bearing:
            // a sender that never mentioned stickiness must follow the type, including after
            // somebody edits the type.
            'sticky' => $this->boolean()->null(),
            'ttl' => $this->integer()->null(),
            'priority' => $this->integer()->notNull()->defaultValue(0),
            'dedupeKey' => $this->string(191),
            'context' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One row per person per message per channel — created when something first happens to
        // that person's copy, not when the message is sent. A message to everyone is one row in
        // the table above and nothing here until somebody reads it.
        $this->createTable(Table::DELIVERIES, [
            'id' => $this->primaryKey(),
            'messageId' => $this->integer()->notNull(),
            'userId' => $this->integer(),
            'channel' => $this->string(64)->notNull()->defaultValue('cp'),
            'state' => $this->string(16)->notNull()->defaultValue('queued'),
            'actionKey' => $this->string(191),
            'dateShown' => $this->dateTime(),
            'dateRead' => $this->dateTime(),
            'dateDismissed' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::MESSAGES, ['uid'], true);
        $this->createIndex(null, Table::MESSAGES, ['plugin']);
        $this->createIndex(null, Table::MESSAGES, ['dedupeKey']);
        $this->createIndex(null, Table::MESSAGES, ['audience']);
        $this->createIndex(null, Table::MESSAGES, ['dateCreated']);

        // The uniqueness that keeps a double-click from filing two receipts.
        $this->createIndex(null, Table::DELIVERIES, ['messageId', 'userId', 'channel'], true);
        $this->createIndex(null, Table::DELIVERIES, ['userId', 'state']);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::DELIVERIES, ['messageId'], Table::MESSAGES, ['id'], 'CASCADE', null);

        // A deleted user takes their receipts with them. Nothing here is worth keeping about
        // somebody who no longer has an account.
        $this->addForeignKey(null, Table::DELIVERIES, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
    }
}
