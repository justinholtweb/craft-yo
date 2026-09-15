<?php

namespace justinholtweb\yo\records;

use craft\db\ActiveRecord;
use justinholtweb\yo\db\Table;

/**
 * @property int $id
 * @property string $uid
 * @property string $plugin
 * @property string $title
 * @property string $body
 * @property string $type
 * @property string $audience
 * @property string|null $channels
 * @property string|null $actions
 * @property bool|null $sticky
 * @property int|null $ttl
 * @property int $priority
 * @property string|null $dedupeKey
 * @property string|null $context
 */
class MessageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::MESSAGES;
    }
}
