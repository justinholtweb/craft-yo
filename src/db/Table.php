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

    /** Messages waiting for somebody's next email digest. One row per (message, recipient). */
    public const EMAIL_QUEUE = '{{%yo_email_queue}}';

    /** The email digest's "last sent" marker. One row. */
    public const DIGESTS = '{{%yo_digests}}';
}
