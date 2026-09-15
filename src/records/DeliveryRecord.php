<?php

namespace justinholtweb\yo\records;

use craft\db\ActiveRecord;
use justinholtweb\yo\db\Table;

/**
 * @property int $id
 * @property int $messageId
 * @property int|null $userId
 * @property string $channel
 * @property string $state
 * @property string|null $actionKey
 * @property string|null $dateShown
 * @property string|null $dateRead
 * @property string|null $dateDismissed
 */
class DeliveryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DELIVERIES;
    }
}
