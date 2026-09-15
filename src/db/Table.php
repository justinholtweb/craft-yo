<?php

namespace justinholtweb\yo\db;

/**
 * Yo's database tables.
 */
abstract class Table
{
    /** One row per stored message. */
    public const MESSAGES = '{{%yo_messages}}';

    /** One row per (message, recipient) pair — the state of that person's copy. */
    public const DELIVERIES = '{{%yo_deliveries}}';
}
