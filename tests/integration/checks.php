<?php
/**
 * Yo integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-yo/tests/integration/checks.php
 *
 * What a unit fixture cannot cover, and this does: the registries with a third-party plugin's
 * listeners actually attached, the two queues against a real database, the receipt events firing
 * with the sender's own context in them, and the guard that stops one person reading another's
 * message.
 *
 * What it deliberately does not cover: the session lane. A console run has no session — Craft's
 * console application throws from `getSession()` on purpose — so every `current`-audience path is
 * asserted here only to the point of "it declined and said why". The session lane is exercised
 * over HTTP by tests/runtime/checks.sh.
 *
 * Idempotent and self-cleaning: it makes its own user and group, addresses everything to them,
 * and deletes both at the end along with every message it sent.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use justinholtweb\yo\channels\BaseChannel;
use justinholtweb\yo\channels\ChannelInterface;
use justinholtweb\yo\db\Table;
use justinholtweb\yo\events\DefineDeliveryEvent;
use justinholtweb\yo\events\DeliveryEvent;
use justinholtweb\yo\events\MessageEvent;
use justinholtweb\yo\events\RegisterChannelsEvent;
use justinholtweb\yo\events\RegisterMessageTypesEvent;
use justinholtweb\yo\models\Delivery;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\models\MessageType;
use justinholtweb\yo\models\Settings;
use justinholtweb\yo\Plugin;
use justinholtweb\yo\services\Channels;
use justinholtweb\yo\services\Messages;
use justinholtweb\yo\services\Types;
use justinholtweb\yo\Yo;
use yii\base\Event;

// ---------------------------------------------------------------------------- test doubles

/**
 * A transport channel, of the kind a third-party plugin would write. It is the shortest one that
 * can be asserted about: it records what it was given and nothing else.
 */
class YoCheckChannel extends BaseChannel implements ChannelInterface
{
    /** @var array<int, array{title: string, userIds: array}> */
    public static array $delivered = [];

    public function handle(): string
    {
        return 'yo-check';
    }

    public function label(): string
    {
        return 'Check channel';
    }

    protected function wantsByDefault(): bool
    {
        return false;
    }

    public function deliver(Message $message, array $userIds): void
    {
        self::$delivered[] = ['title' => $message->title, 'userIds' => $userIds];
    }
}

/** A channel that throws, to prove one broken transport cannot lose the message everywhere else. */
class YoExplodingChannel extends BaseChannel implements ChannelInterface
{
    public function handle(): string
    {
        return 'yo-explode';
    }

    public function label(): string
    {
        return 'Exploding channel';
    }

    protected function wantsByDefault(): bool
    {
        return false;
    }

    public function deliver(Message $message, array $userIds): void
    {
        throw new RuntimeException('This channel is broken on purpose.');
    }
}

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$original = $plugin->getSettings()->toArray();

/** A tag every message this run sends carries, so cleanup can find them and nothing else. */
const RUN_TAG = 'yo-checks';

function configure(array $overrides): Settings
{
    $plugin = Plugin::getInstance();
    $settings = new Settings();
    $settings->setAttributes($overrides, false);
    $plugin->setSettings($settings->toArray());

    return $plugin->getSettings();
}

// ---------------------------------------------------------------------------- fixtures

section('Fixtures');

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);

$group = new craft\models\UserGroup([
    'name' => 'Yo checks ' . $suffix,
    'handle' => 'yoChecks' . $suffix,
]);

check('a user group is created', function() use ($group) {
    return Craft::$app->getUserGroups()->saveGroup($group) ?: 'saveGroup returned false';
});

$testUser = new User([
    'username' => 'yo-checks-' . $suffix,
    'email' => "yo-checks-$suffix@example.test",
    'firstName' => 'Yo',
    'lastName' => 'Checks',
]);

check('a test user is created', function() use ($testUser) {
    return Craft::$app->getElements()->saveElement($testUser) ?: implode(', ', $testUser->getErrorSummary(true));
});

check('the group can access the control panel', function() use ($group) {
    // Not incidental. `User::getPreferences()` returns nothing at all for a user who cannot reach
    // the control panel, so a user without this reads every preference back as its default — and
    // the panel-position check below would pass for the wrong reason.
    return Craft::$app->getUserPermissions()->saveGroupPermissions($group->id, ['accesscp'])
        ?: 'saveGroupPermissions returned false';
});

check('the test user joins the group', function() use ($testUser, $group) {
    return Craft::$app->getUsers()->assignUserToGroups($testUser->id, [$group->id]) ?: 'assign returned false';
});

$otherUser = new User([
    'username' => 'yo-other-' . $suffix,
    'email' => "yo-other-$suffix@example.test",
]);

check('a second user is created, to prove people cannot read each other’s messages', function() use ($otherUser) {
    return Craft::$app->getElements()->saveElement($otherUser) ?: implode(', ', $otherUser->getErrorSummary(true));
});

// Everything below runs as the test user. The console user component supports this outright.
Craft::$app->getUser()->setIdentity($testUser);

check('the console request is signed in as the test user', function() use ($testUser) {
    return Craft::$app->getUser()->getId() === $testUser->id ?: 'identity did not take';
});

/** Sends a stored message addressed at the test user, tagged for cleanup. */
function sendToTestUser(array $config = []): Message
{
    global $testUser;

    $message = new Message(array_merge([
        'title' => 'Check message',
        'plugin' => 'yo',
        'audience' => 'user:' . $testUser->id,
        'context' => ['tag' => RUN_TAG],
    ], $config));

    $message->send();

    return $message;
}

// ---------------------------------------------------------------------------- types

section('Message types');

check('the five shipped types are registered', function() use ($plugin) {
    $handles = array_keys($plugin->types->getAllTypes());
    sort($handles);

    return $handles === ['error', 'notice', 'success', 'tip', 'warning'] ?: implode(', ', $handles);
});

check('every shipped type has a label, a colour and an icon', function() use ($plugin) {
    foreach ($plugin->types->getAllTypes() as $type) {
        if ($type->label === '' || $type->color === '' || $type->icon === '') {
            return "{$type->handle} is missing one of them";
        }
    }

    return true;
});

check('errors and warnings are sticky, the rest are not', function() use ($plugin) {
    $types = $plugin->types->getAllTypes();

    return ($types['error']->sticky && $types['warning']->sticky
        && !$types['success']->sticky && !$types['notice']->sticky && !$types['tip']->sticky)
        ?: 'stickiness is not what the defaults say';
});

check('errors sort above warnings, which sort above everything else', function() use ($plugin) {
    $types = $plugin->types->getAllTypes();

    return ($types['error']->priority > $types['warning']->priority
        && $types['warning']->priority > $types['notice']->priority)
        ?: 'priorities do not order';
});

check('a plugin can register its own type', function() use ($plugin) {
    $handler = function(RegisterMessageTypesEvent $event) {
        $event->types['deployed'] = new MessageType([
            'handle' => 'deployed',
            'label' => 'Deployed',
            'color' => '#2f9e6b',
            'sticky' => true,
        ]);
    };

    Event::on(Types::class, Types::EVENT_REGISTER_MESSAGE_TYPES, $handler);
    $plugin->types->reset();

    $type = $plugin->types->getTypeByHandle('deployed');

    Event::off(Types::class, Types::EVENT_REGISTER_MESSAGE_TYPES, $handler);
    $plugin->types->reset();

    return ($type !== null && $type->color === '#2f9e6b') ?: 'the registered type did not come back';
});

check('a registered type is re-keyed on its own handle, whatever key it arrived under', function() use ($plugin) {
    $handler = function(RegisterMessageTypesEvent $event) {
        // Appended with no key at all — the shape a listener writes when it is not thinking.
        $event->types[] = new MessageType(['handle' => 'shipped', 'label' => 'Shipped']);
    };

    Event::on(Types::class, Types::EVENT_REGISTER_MESSAGE_TYPES, $handler);
    $plugin->types->reset();

    $found = $plugin->types->getTypeByHandle('shipped');

    Event::off(Types::class, Types::EVENT_REGISTER_MESSAGE_TYPES, $handler);
    $plugin->types->reset();

    return $found !== null ?: 'an unkeyed type could not be found by handle';
});

check('a message with an unknown type falls back to notice rather than throwing', function() {
    $message = new Message(['title' => 'x', 'type' => 'nonsense-that-nobody-registered']);

    return $message->getMessageType()->handle === 'notice' ?: $message->getMessageType()->handle;
});

// ---------------------------------------------------------------------------- channels

section('Channels');

check('the three shipped channels are registered', function() use ($plugin) {
    $handles = array_keys($plugin->channels->getAllChannels());
    sort($handles);

    return $handles === ['cp', 'log', 'site'] ?: implode(', ', $handles);
});

check('the control panel channel is queued by Yo; the log channel is a transport', function() use ($plugin) {
    return ($plugin->channels->getChannelByHandle('cp')->isQueued()
        && !$plugin->channels->getChannelByHandle('log')->isQueued())
        ?: 'isQueued() is the wrong way round';
});

check('a message naming no channels goes to the control panel and not the front end', function() use ($plugin) {
    configure(['siteChannel' => true]);
    $message = new Message(['title' => 'x']);
    $handles = array_keys($plugin->channels->channelsFor($message));

    return $handles === ['cp'] ?: implode(', ', $handles);
});

check('the front-end channel takes a message that asks for it by name', function() use ($plugin) {
    configure(['siteChannel' => true]);
    $message = new Message(['title' => 'x', 'channels' => ['site']]);

    return array_keys($plugin->channels->channelsFor($message)) === ['site'] ?: 'site declined a message addressed to it';
});

check('the front-end channel refuses everything while it is switched off', function() use ($plugin) {
    configure(['siteChannel' => false]);
    $message = new Message(['title' => 'x', 'channels' => ['site']]);

    return $plugin->channels->channelsFor($message) === [] ?: 'the site channel accepted a message while switched off';
});

check('the log channel takes everything once logMessages is on', function() use ($plugin) {
    configure(['logMessages' => true]);
    $handles = array_keys($plugin->channels->channelsFor(new Message(['title' => 'x'])));
    configure(['logMessages' => false]);

    return in_array('log', $handles, true) ?: implode(', ', $handles);
});

check('a plugin can register a channel of its own, and it receives messages', function() use ($plugin, $testUser) {
    $handler = function(RegisterChannelsEvent $event) {
        $event->channels[] = YoCheckChannel::class;
    };

    Event::on(Channels::class, Channels::EVENT_REGISTER_CHANNELS, $handler);
    $plugin->channels->reset();

    YoCheckChannel::$delivered = [];

    (new Message([
        'title' => 'To the test channel',
        'channels' => ['yo-check'],
        'audience' => 'user:' . $testUser->id,
    ]))->send();

    $delivered = YoCheckChannel::$delivered;

    Event::off(Channels::class, Channels::EVENT_REGISTER_CHANNELS, $handler);
    $plugin->channels->reset();

    return (count($delivered) === 1 && $delivered[0]['title'] === 'To the test channel')
        ?: 'the custom channel got ' . count($delivered) . ' messages';
});

check('a transport channel is handed the recipient ids', function() use ($plugin, $testUser) {
    $handler = fn(RegisterChannelsEvent $event) => $event->channels[] = YoCheckChannel::class;

    Event::on(Channels::class, Channels::EVENT_REGISTER_CHANNELS, $handler);
    $plugin->channels->reset();
    YoCheckChannel::$delivered = [];

    (new Message([
        'title' => 'With recipients',
        'channels' => ['yo-check'],
        'audience' => 'user:' . $testUser->id,
    ]))->send();

    $ids = YoCheckChannel::$delivered[0]['userIds'] ?? [];

    Event::off(Channels::class, Channels::EVENT_REGISTER_CHANNELS, $handler);
    $plugin->channels->reset();

    return $ids === [$testUser->id] ?: 'got ' . json_encode($ids);
});

check('a channel that throws does not lose the message everywhere else', function() use ($plugin, $testUser) {
    $handler = fn(RegisterChannelsEvent $event) => $event->channels[] = YoExplodingChannel::class;

    Event::on(Channels::class, Channels::EVENT_REGISTER_CHANNELS, $handler);
    $plugin->channels->reset();

    $sent = (new Message([
        'title' => 'Survives a broken channel',
        'channels' => ['yo-explode', 'cp'],
        'audience' => 'user:' . $testUser->id,
        'context' => ['tag' => RUN_TAG],
    ]))->send();

    Event::off(Channels::class, Channels::EVENT_REGISTER_CHANNELS, $handler);
    $plugin->channels->reset();

    return $sent === true ?: 'send() reported failure because one transport threw';
});

check('a registration that is not a channel is skipped rather than fatal', function() use ($plugin) {
    $handler = fn(RegisterChannelsEvent $event) => $event->channels[] = stdClass::class;

    Event::on(Channels::class, Channels::EVENT_REGISTER_CHANNELS, $handler);
    $plugin->channels->reset();

    $handles = array_keys($plugin->channels->getAllChannels());

    Event::off(Channels::class, Channels::EVENT_REGISTER_CHANNELS, $handler);
    $plugin->channels->reset();

    return count($handles) === 3 ?: implode(', ', $handles);
});

// ---------------------------------------------------------------------------- the message model

section('The message model');

check('the builder reads the way the documentation says it does', function() {
    $message = Yo::say('Import finished')
        ->body('412 entries in, 3 skipped.')
        ->success()
        ->action('Review the log', url: '/admin/x', primary: true, key: 'review')
        ->dedupe('transport:import')
        ->priority(5)
        ->ttl(60);

    return ($message->title === 'Import finished'
        && $message->body === '412 entries in, 3 skipped.'
        && $message->type === 'success'
        && count($message->actions) === 1
        && $message->actions[0]->primary
        && $message->dedupeKey === 'transport:import'
        && $message->priority === 5
        && $message->ttl === 60) ?: 'the builder did not assemble';
});

check('every message gets a uid without being asked', function() {
    $a = new Message(['title' => 'a']);
    $b = new Message(['title' => 'b']);

    return ($a->uid !== '' && $a->uid !== $b->uid) ?: 'uids are empty or repeated';
});

check('a message with no title is refused', function() {
    return (new Message(['title' => '']))->send() === false ?: 'an untitled message was sent';
});

check('an audience that is not a shape Yo understands is refused', function() {
    return (new Message(['title' => 'x', 'audience' => 'user:not-a-number']))->send() === false
        ?: 'a malformed audience was accepted';
});

check('->to() takes a user element, an id or a string', function() use ($testUser) {
    $byElement = (new Message(['title' => 'x']))->to($testUser)->audience;
    $byId = (new Message(['title' => 'x']))->to($testUser->id)->audience;
    $byString = (new Message(['title' => 'x']))->to('group:editors')->audience;

    return ($byElement === 'user:' . $testUser->id && $byId === $byElement && $byString === 'group:editors')
        ?: "$byElement / $byId / $byString";
});

check('stickiness defaults to the type, and an explicit false beats it', function() {
    $error = new Message(['title' => 'x', 'type' => 'error']);
    $quiet = (new Message(['title' => 'x', 'type' => 'error']))->sticky(false);
    $ok = new Message(['title' => 'x', 'type' => 'success']);

    return ($error->getIsSticky() && !$quiet->getIsSticky() && !$ok->getIsSticky())
        ?: 'stickiness did not resolve through the type';
});

check('a message for the current user is not stored; one for anybody else is', function() use ($testUser) {
    return ((new Message(['title' => 'x']))->getIsStored() === false
        && (new Message(['title' => 'x']))->to($testUser)->getIsStored() === true
        && (new Message(['title' => 'x']))->everyone()->getIsStored() === true)
        ?: 'getIsStored() disagrees';
});

check('a message past its ttl reports itself expired', function() {
    $message = new Message(['title' => 'x', 'ttl' => 1]);
    $message->dateCreated = (new DateTime())->modify('-1 hour');

    return $message->getIsExpired() ?: 'an hour-old message with a one-second ttl is not expired';
});

check('a ttl of zero means it never expires', function() {
    $message = new Message(['title' => 'x', 'ttl' => 0]);
    $message->dateCreated = (new DateTime())->modify('-10 years');

    return $message->getIsExpired() === false ?: 'a zero ttl expired anyway';
});

check('a null ttl follows the setting, and follows it as the setting changes', function() {
    configure(['defaultTtl' => 5]);
    $message = new Message(['title' => 'x']);
    $message->dateCreated = (new DateTime())->modify('-1 hour');
    $expiredWithShortTtl = $message->getIsExpired();

    configure(['defaultTtl' => 0]);
    $expiredWithNoTtl = $message->getIsExpired();

    configure(['defaultTtl' => 604800]);

    return ($expiredWithShortTtl && !$expiredWithNoTtl) ?: 'a null ttl is not reading the setting';
});

check('the wire shape carries the type’s colour and icon, not the handle alone', function() {
    $wire = (new Message(['title' => 'x', 'type' => 'error']))->toWire();

    return ($wire['color'] === '#E4574C' && str_contains($wire['icon'], '<svg') && $wire['typeLabel'] === 'Error')
        ?: json_encode($wire);
});

check('the wire priority falls back to the type when the message did not set one', function() {
    $wire = (new Message(['title' => 'x', 'type' => 'error']))->toWire();

    return $wire['priority'] === 20 ?: 'got ' . $wire['priority'];
});

check('a message round-trips through storage with its actions intact', function() {
    $before = Yo::say('Round trip')
        ->body('and back')
        ->warning()
        ->action('Retry', action: 'transport/imports/retry', params: ['id' => 4], key: 'retry')
        ->dedupe('k')
        ->context(['a' => 1]);

    $after = Message::fromStorage($before->toStorage());

    return ($after->title === $before->title
        && $after->type === 'warning'
        && $after->uid === $before->uid
        && $after->context === ['a' => 1]
        && count($after->actions) === 1
        && $after->actions[0]->params === ['id' => 4]
        && $after->actions[0]->getKey() === 'retry') ?: 'the round trip lost something';
});

check('an action with no key of its own answers to its label', function() {
    $message = Yo::say('x')->action('Undo');

    return $message->actions[0]->getKey() === 'Undo' ?: $message->actions[0]->getKey();
});

// ---------------------------------------------------------------------------- sending

section('Sending');

check('a message addressed at a user is written down', function() use ($testUser) {
    $message = sendToTestUser(['title' => 'Stored for one person']);

    $row = (new Query())->from(Table::MESSAGES)->where(['uid' => $message->uid])->one();

    return ($row !== null && $row['audience'] === 'user:' . $testUser->id) ?: 'no row';
});

check('the resolved channels are stored with the message, not recomputed on read', function() {
    $message = sendToTestUser(['title' => 'Channels are stored']);
    $row = (new Query())->from(Table::MESSAGES)->where(['uid' => $message->uid])->one();

    return json_decode($row['channels'], true) === ['cp'] ?: $row['channels'];
});

check('a message to everyone writes one row and no deliveries', function() {
    $message = (new Message([
        'title' => 'To everyone',
        'plugin' => 'yo',
        'audience' => Message::AUDIENCE_EVERYONE,
        'context' => ['tag' => RUN_TAG],
    ]));
    $message->send();

    $row = (new Query())->from(Table::MESSAGES)->where(['uid' => $message->uid])->one();
    $deliveries = (new Query())->from(Table::DELIVERIES)->where(['messageId' => $row['id']])->count();

    return ((int)$deliveries === 0) ?: "a broadcast wrote $deliveries delivery rows";
});

check('EVENT_BEFORE_SEND can cancel a message', function() {
    $handler = function(MessageEvent $event) {
        if ($event->message->title === 'Cancel me') {
            $event->isValid = false;
        }
    };

    Event::on(Messages::class, Messages::EVENT_BEFORE_SEND, $handler);
    $sent = sendToTestUser(['title' => 'Cancel me']);
    $result = $sent->id;
    Event::off(Messages::class, Messages::EVENT_BEFORE_SEND, $handler);

    return $result === null ?: 'a cancelled message was still stored';
});

check('EVENT_BEFORE_SEND can rewrite another plugin’s message', function() {
    $handler = function(MessageEvent $event) {
        if ($event->message->title === 'Rewrite me') {
            $event->message->title = 'Rewritten';
        }
    };

    Event::on(Messages::class, Messages::EVENT_BEFORE_SEND, $handler);
    $message = sendToTestUser(['title' => 'Rewrite me']);
    Event::off(Messages::class, Messages::EVENT_BEFORE_SEND, $handler);

    $row = (new Query())->from(Table::MESSAGES)->where(['uid' => $message->uid])->one();

    return ($row['title'] ?? null) === 'Rewritten' ?: 'the rewrite did not reach the row';
});

check('EVENT_AFTER_SEND reports the channels it went to', function() {
    $seen = [];
    $handler = function(MessageEvent $event) use (&$seen) {
        $seen = $event->channels;
    };

    Event::on(Messages::class, Messages::EVENT_AFTER_SEND, $handler);
    sendToTestUser(['title' => 'After send']);
    Event::off(Messages::class, Messages::EVENT_AFTER_SEND, $handler);

    return $seen === ['cp'] ?: json_encode($seen);
});

check('EVENT_DEFINE_DELIVERY can add a channel the message never asked for', function() use ($plugin) {
    $registerHandler = fn(RegisterChannelsEvent $event) => $event->channels[] = YoCheckChannel::class;
    $routeHandler = function(DefineDeliveryEvent $event) {
        if ($event->message->title === 'Route me') {
            $event->channels[] = 'yo-check';
        }
    };

    Event::on(Channels::class, Channels::EVENT_REGISTER_CHANNELS, $registerHandler);
    Event::on(Messages::class, Messages::EVENT_DEFINE_DELIVERY, $routeHandler);
    $plugin->channels->reset();
    YoCheckChannel::$delivered = [];

    sendToTestUser(['title' => 'Route me']);
    $count = count(YoCheckChannel::$delivered);

    Event::off(Messages::class, Messages::EVENT_DEFINE_DELIVERY, $routeHandler);
    Event::off(Channels::class, Channels::EVENT_REGISTER_CHANNELS, $registerHandler);
    $plugin->channels->reset();

    return $count === 1 ?: "the added channel received $count messages";
});

check('EVENT_DEFINE_DELIVERY can hold a message back from a channel', function() {
    $handler = function(DefineDeliveryEvent $event) {
        if ($event->message->title === 'Hold me back') {
            $event->channels = array_values(array_diff($event->channels, ['cp']));
        }
    };

    Event::on(Messages::class, Messages::EVENT_DEFINE_DELIVERY, $handler);
    $message = sendToTestUser(['title' => 'Hold me back']);
    Event::off(Messages::class, Messages::EVENT_DEFINE_DELIVERY, $handler);

    return $message->id === null ?: 'a message held back from every channel was still stored';
});

check('the sending plugin is worked out when the sender did not say', function() {
    // Called from this file, which belongs to no plugin, so the handle comes back empty rather
    // than wrong. Getting *nothing* is the correct answer here; getting "yo" would not be.
    $message = new Message(['title' => 'Who sent this', 'audience' => Message::AUDIENCE_EVERYONE]);
    $message->send();

    $ok = $message->plugin === '';
    Plugin::getInstance()->messages->deleteStoredWhere(['uid' => $message->uid]);

    return $ok ?: "attributed to '{$message->plugin}'";
});

check('a message sent to the current user from the console is declined, not silently dropped', function() {
    $before = (new Query())->from(Table::MESSAGES)->count();
    $sent = Yo::success('Nobody will see this');
    $after = (new Query())->from(Table::MESSAGES)->count();

    // It reports success — every channel that could take it did — but nothing was written, and a
    // warning went to the log saying why.
    return ($sent === true && $before === $after) ?: 'a console `current` message wrote a row';
});

// ---------------------------------------------------------------------------- reading

section('Reading');

check('a message addressed at somebody comes back for them', function() use ($testUser) {
    $message = sendToTestUser(['title' => 'For the test user']);

    $uids = array_map(
        fn(Delivery $d) => $d->messageUid,
        Plugin::getInstance()->messages->forRecipient('cp', $testUser->id, false),
    );

    return in_array($message->uid, $uids, true) ?: 'it did not come back';
});

check('and does not come back for anybody else', function() use ($testUser, $otherUser) {
    $message = sendToTestUser(['title' => 'Not for you']);

    $uids = array_map(
        fn(Delivery $d) => $d->messageUid,
        Plugin::getInstance()->messages->forRecipient('cp', $otherUser->id, false),
    );

    return !in_array($message->uid, $uids, true) ?: 'somebody else could see it';
});

check('a message to a group reaches everyone in the group', function() use ($testUser, $group, $otherUser) {
    $message = new Message([
        'title' => 'To the group',
        'plugin' => 'yo',
        'audience' => 'group:' . $group->handle,
        'context' => ['tag' => RUN_TAG],
    ]);
    $message->send();

    $messages = Plugin::getInstance()->messages;
    $mine = $messages->getMessageByUid($message->uid, $testUser->id);
    $theirs = $messages->getMessageByUid($message->uid, $otherUser->id);

    return ($mine !== null && $theirs === null) ?: 'group targeting is not filtering by membership';
});

check('a message to everyone reaches somebody in no groups at all', function() use ($otherUser) {
    $message = new Message([
        'title' => 'Broadcast',
        'plugin' => 'yo',
        'audience' => Message::AUDIENCE_EVERYONE,
        'context' => ['tag' => RUN_TAG],
    ]);
    $message->send();

    return Plugin::getInstance()->messages->getMessageByUid($message->uid, $otherUser->id) !== null
        ?: 'a broadcast missed a user with no groups';
});

check('an anonymous reader gets nothing from the stored lane', function() {
    return Plugin::getInstance()->messages->forRecipient('cp', null, false) === []
        ?: 'a guest was handed stored messages';
});

check('messages come back highest priority first', function() use ($testUser) {
    Plugin::getInstance()->messages->clearFor($testUser->id);

    sendToTestUser(['title' => 'Low', 'type' => 'notice']);
    sendToTestUser(['title' => 'High', 'type' => 'error']);

    $titles = array_map(
        fn(Delivery $d) => $d->message->title,
        Plugin::getInstance()->messages->forRecipient('cp', $testUser->id, false),
    );

    return ($titles[0] ?? null) === 'High' ?: implode(' | ', $titles);
});

check('a dedupe key replaces the earlier message rather than stacking under it', function() use ($testUser) {
    Plugin::getInstance()->messages->clearFor($testUser->id);

    sendToTestUser(['title' => 'Progress: 1 of 3', 'dedupeKey' => 'checks:progress']);
    sendToTestUser(['title' => 'Progress: 2 of 3', 'dedupeKey' => 'checks:progress']);
    sendToTestUser(['title' => 'Progress: 3 of 3', 'dedupeKey' => 'checks:progress']);

    $titles = array_map(
        fn(Delivery $d) => $d->message->title,
        Plugin::getInstance()->messages->forRecipient('cp', $testUser->id, false),
    );

    return ($titles === ['Progress: 3 of 3']) ?: implode(' | ', $titles);
});

check('a person is never handed more than the cap', function() use ($testUser) {
    Plugin::getInstance()->messages->clearFor($testUser->id);
    configure(['maxPerRecipient' => 3]);

    for ($i = 0; $i < 6; $i++) {
        sendToTestUser(['title' => "Capped $i"]);
    }

    $count = count(Plugin::getInstance()->messages->forRecipient('cp', $testUser->id, false));
    configure(['maxPerRecipient' => 50]);

    return $count === 3 ?: "got $count";
});

check('a message on the front-end channel does not appear in the control panel', function() use ($testUser) {
    configure(['siteChannel' => true]);

    $message = sendToTestUser(['title' => 'Front end only', 'channels' => ['site']]);

    $messages = Plugin::getInstance()->messages;
    $inCp = $messages->getMessageByUid($message->uid, $testUser->id, 'cp');
    $inSite = $messages->getMessageByUid($message->uid, $testUser->id, 'site');

    configure(['siteChannel' => false]);

    return ($inCp === null && $inSite !== null) ?: 'the channels are not separating';
});

// ---------------------------------------------------------------------------- receipts

section('Receipts');

check('dismissing a message takes it off the list', function() use ($testUser) {
    $message = sendToTestUser(['title' => 'Dismiss me']);
    $messages = Plugin::getInstance()->messages;

    $messages->dismiss($message->uid, $testUser->id);

    return $messages->getMessageByUid($message->uid, $testUser->id) === null ?: 'still there';
});

check('a dismissal is a row, so it survives the next request', function() use ($testUser) {
    $message = sendToTestUser(['title' => 'Dismissed for good']);
    Plugin::getInstance()->messages->dismiss($message->uid, $testUser->id);

    $row = (new Query())
        ->from(['d' => Table::DELIVERIES])
        ->innerJoin(['m' => Table::MESSAGES], '[[m.id]] = [[d.messageId]]')
        ->where(['m.uid' => $message->uid, 'd.userId' => $testUser->id])
        ->one();

    return ($row !== null && $row['state'] === 'dismissed' && $row['dateDismissed'] !== null)
        ?: 'no dismissal row';
});

check('one person dismissing a broadcast leaves it on everybody else’s list', function() use ($testUser, $otherUser) {
    $message = new Message([
        'title' => 'Everyone sees this',
        'plugin' => 'yo',
        'audience' => Message::AUDIENCE_EVERYONE,
        'context' => ['tag' => RUN_TAG],
    ]);
    $message->send();

    $messages = Plugin::getInstance()->messages;
    $messages->dismiss($message->uid, $testUser->id);

    return ($messages->getMessageByUid($message->uid, $testUser->id) === null
        && $messages->getMessageByUid($message->uid, $otherUser->id) !== null)
        ?: 'a dismissal leaked across recipients';
});

check('EVENT_MESSAGE_DISMISSED fires with the sender’s own context', function() use ($testUser) {
    $context = null;
    $handler = function(DeliveryEvent $event) use (&$context) {
        $context = $event->message->context;
    };

    $message = sendToTestUser(['title' => 'With context', 'context' => ['tag' => RUN_TAG, 'importId' => 88]]);

    Event::on(Messages::class, Messages::EVENT_MESSAGE_DISMISSED, $handler);
    Plugin::getInstance()->messages->dismiss($message->uid, $testUser->id);
    Event::off(Messages::class, Messages::EVENT_MESSAGE_DISMISSED, $handler);

    return ($context['importId'] ?? null) === 88 ?: json_encode($context);
});

check('EVENT_MESSAGE_READ fires and records when', function() use ($testUser) {
    $fired = false;
    $handler = function() use (&$fired) {
        $fired = true;
    };

    $message = sendToTestUser(['title' => 'Read me']);

    Event::on(Messages::class, Messages::EVENT_MESSAGE_READ, $handler);
    Plugin::getInstance()->messages->markRead($message->uid, $testUser->id);
    Event::off(Messages::class, Messages::EVENT_MESSAGE_READ, $handler);

    $row = (new Query())
        ->from(['d' => Table::DELIVERIES])
        ->innerJoin(['m' => Table::MESSAGES], '[[m.id]] = [[d.messageId]]')
        ->where(['m.uid' => $message->uid])
        ->one();

    return ($fired && $row['dateRead'] !== null) ?: 'read was not recorded';
});

check('EVENT_MESSAGE_ACTIONED names the button that was pressed', function() use ($testUser) {
    $key = null;
    $handler = function(DeliveryEvent $event) use (&$key) {
        $key = $event->actionKey;
    };

    $message = sendToTestUser(['title' => 'Press one']);
    $message = Plugin::getInstance()->messages->getMessageByUid($message->uid, $testUser->id);

    Event::on(Messages::class, Messages::EVENT_MESSAGE_ACTIONED, $handler);
    Plugin::getInstance()->messages->actioned($message->uid, $testUser->id, 'retry');
    Event::off(Messages::class, Messages::EVENT_MESSAGE_ACTIONED, $handler);

    return $key === 'retry' ?: var_export($key, true);
});

check('a button that dismisses does dismiss', function() use ($testUser) {
    $message = new Message([
        'title' => 'Undo it',
        'plugin' => 'yo',
        'audience' => 'user:' . $testUser->id,
        'context' => ['tag' => RUN_TAG],
    ]);
    $message->action('Undo', url: '/admin', key: 'undo', dismisses: true);
    $message->send();

    $messages = Plugin::getInstance()->messages;
    $messages->actioned($message->uid, $testUser->id, 'undo');

    return $messages->getMessageByUid($message->uid, $testUser->id) === null ?: 'still on the list';
});

check('a button that does not dismiss leaves the message alone', function() use ($testUser) {
    $message = new Message([
        'title' => 'Keep me',
        'plugin' => 'yo',
        'audience' => 'user:' . $testUser->id,
        'context' => ['tag' => RUN_TAG],
    ]);
    $message->action('More detail', url: '/admin', key: 'detail', dismisses: false);
    $message->send();

    $messages = Plugin::getInstance()->messages;
    $messages->actioned($message->uid, $testUser->id, 'detail');

    return $messages->getMessageByUid($message->uid, $testUser->id) !== null ?: 'it dismissed itself';
});

check('a receipt for a message you cannot see does nothing', function() use ($testUser, $otherUser) {
    $message = sendToTestUser(['title' => 'Yours, not theirs']);

    $dismissed = Plugin::getInstance()->messages->dismiss($message->uid, $otherUser->id);

    return ($dismissed === false
        && Plugin::getInstance()->messages->getMessageByUid($message->uid, $testUser->id) !== null)
        ?: 'somebody dismissed a message that was not theirs';
});

check('a receipt state never walks backwards', function() use ($testUser) {
    $message = sendToTestUser(['title' => 'Read then shown']);
    $messages = Plugin::getInstance()->messages;

    $messages->markRead($message->uid, $testUser->id);
    $messages->markShown($message->uid, $testUser->id);

    $row = (new Query())
        ->from(['d' => Table::DELIVERIES])
        ->innerJoin(['m' => Table::MESSAGES], '[[m.id]] = [[d.messageId]]')
        ->where(['m.uid' => $message->uid])
        ->one();

    return $row['state'] === 'read' ?: "state fell back to {$row['state']}";
});

check('two receipts for the same copy make one row, not two', function() use ($testUser) {
    $message = sendToTestUser(['title' => 'Twice']);
    $messages = Plugin::getInstance()->messages;

    $messages->markShown($message->uid, $testUser->id);
    $messages->markRead($message->uid, $testUser->id);

    $count = (new Query())
        ->from(['d' => Table::DELIVERIES])
        ->innerJoin(['m' => Table::MESSAGES], '[[m.id]] = [[d.messageId]]')
        ->where(['m.uid' => $message->uid])
        ->count();

    return (int)$count === 1 ?: "got $count rows";
});

check('clearing dismisses everything waiting and reports how many', function() use ($testUser) {
    $messages = Plugin::getInstance()->messages;
    $messages->clearFor($testUser->id);

    sendToTestUser(['title' => 'One']);
    sendToTestUser(['title' => 'Two']);
    sendToTestUser(['title' => 'Three']);

    $cleared = $messages->clearFor($testUser->id);

    return ($cleared === 3 && $messages->countFor($testUser->id) === 0) ?: "cleared $cleared";
});

// ---------------------------------------------------------------------------- expiry

section('Expiry');

check('an expired message is not offered', function() use ($testUser) {
    $message = sendToTestUser(['title' => 'Long gone', 'ttl' => 1]);

    Craft::$app->getDb()->createCommand()
        ->update(Table::MESSAGES, ['dateCreated' => '2001-01-01 00:00:00'], ['uid' => $message->uid])
        ->execute();

    return Plugin::getInstance()->messages->getMessageByUid($message->uid, $testUser->id) === null
        ?: 'an expired message was still offered';
});

check('purging drops expired rows and leaves live ones', function() use ($testUser) {
    $stale = sendToTestUser(['title' => 'Stale', 'ttl' => 1]);
    $fresh = sendToTestUser(['title' => 'Fresh', 'ttl' => 0]);

    Craft::$app->getDb()->createCommand()
        ->update(Table::MESSAGES, ['dateCreated' => '2001-01-01 00:00:00'], ['uid' => $stale->uid])
        ->execute();

    Plugin::getInstance()->messages->purgeExpired();

    $staleRow = (new Query())->from(Table::MESSAGES)->where(['uid' => $stale->uid])->exists();
    $freshRow = (new Query())->from(Table::MESSAGES)->where(['uid' => $fresh->uid])->exists();

    return (!$staleRow && $freshRow) ?: 'the purge took the wrong rows';
});

check('EVENT_MESSAGE_EXPIRED fires as one goes', function() use ($testUser) {
    $seen = null;
    $handler = function(MessageEvent $event) use (&$seen) {
        $seen = $event->message->title;
    };

    $message = sendToTestUser(['title' => 'About to expire', 'ttl' => 1]);

    Craft::$app->getDb()->createCommand()
        ->update(Table::MESSAGES, ['dateCreated' => '2001-01-01 00:00:00'], ['uid' => $message->uid])
        ->execute();

    Event::on(Messages::class, Messages::EVENT_MESSAGE_EXPIRED, $handler);
    Plugin::getInstance()->messages->purgeExpired();
    Event::off(Messages::class, Messages::EVENT_MESSAGE_EXPIRED, $handler);

    return $seen === 'About to expire' ?: var_export($seen, true);
});

check('deleting a message takes its receipts with it', function() use ($testUser) {
    $message = sendToTestUser(['title' => 'Cascade']);
    Plugin::getInstance()->messages->markRead($message->uid, $testUser->id);

    $id = (new Query())->select('id')->from(Table::MESSAGES)->where(['uid' => $message->uid])->scalar();
    Plugin::getInstance()->messages->deleteStoredWhere(['uid' => $message->uid]);

    return (new Query())->from(Table::DELIVERIES)->where(['messageId' => $id])->exists() === false
        ?: 'orphaned delivery rows';
});

// ---------------------------------------------------------------------------- the panel

section('The panel');

check('the panel reports the position from the settings when nobody has moved it', function() use ($plugin) {
    configure(['defaultPosition' => Settings::POSITION_TOP_LEFT]);
    $position = $plugin->panel->positionForCurrentUser();
    configure(['defaultPosition' => Settings::POSITION_BOTTOM_RIGHT]);

    return $position === 'top-left' ?: $position;
});

check('a dragged position is remembered for one person and not the others', function() use ($plugin, $testUser, $otherUser) {
    $plugin->panel->savePositionForCurrentUser('top-center');

    $mine = $plugin->panel->positionForCurrentUser();

    Craft::$app->getUser()->setIdentity($otherUser);
    $theirs = $plugin->panel->positionForCurrentUser();
    Craft::$app->getUser()->setIdentity($testUser);

    return ($mine === 'top-center' && $theirs === 'bottom-right') ?: "$mine / $theirs";
});

check('the panel config carries everything the browser needs', function() use ($plugin) {
    $config = $plugin->panel->config();

    foreach (['position', 'pollInterval', 'autoDismissAfter', 'idleMascot', 'adoptCraftFlashes'] as $key) {
        if (!array_key_exists($key, $config)) {
            return "missing $key";
        }
    }

    return true;
});

check('taking the payload marks what it handed over as shown', function() use ($plugin, $testUser) {
    $plugin->messages->clearFor($testUser->id);
    $message = sendToTestUser(['title' => 'Shown on delivery']);

    $plugin->panel->payload();

    $row = (new Query())
        ->from(['d' => Table::DELIVERIES])
        ->innerJoin(['m' => Table::MESSAGES], '[[m.id]] = [[d.messageId]]')
        ->where(['m.uid' => $message->uid])
        ->one();

    return ($row !== null && $row['dateShown'] !== null) ?: 'nothing was recorded as shown';
});

check('the panel renders its list, and renders the empty state when there is none', function() use ($plugin, $testUser) {
    $plugin->messages->clearFor($testUser->id);

    $empty = $plugin->panel->render([]);
    $full = $plugin->panel->render([(new Message(['title' => 'Rendered', 'type' => 'success']))->toWire()]);

    return (str_contains($empty, 'yo-empty')
        && str_contains($full, 'Rendered')
        && str_contains($full, 'yo-message--success')) ?: 'the render is not what it should be';
});

check('the rendered list escapes a message title rather than trusting it', function() use ($plugin) {
    $html = $plugin->panel->render([
        (new Message(['title' => '<script>alert(1)</script>']))->toWire(),
    ]);

    return (!str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'))
        ?: 'a title was rendered as markup';
});

check('the type icon is still rendered as markup, because that one is ours', function() use ($plugin) {
    $html = $plugin->panel->render([(new Message(['title' => 'x', 'type' => 'error']))->toWire()]);

    return str_contains($html, '<svg') ?: 'the icon was escaped';
});

// ---------------------------------------------------------------------------- the front end

section('The front end');

check('the front-end list renders with the configured class prefix', function() use ($plugin) {
    configure(['siteChannel' => true, 'siteClassPrefix' => 'shout']);

    $html = $plugin->frontend->render([(new Message(['title' => 'Hello', 'type' => 'notice']))->toWire()]);

    configure(['siteChannel' => false, 'siteClassPrefix' => 'yo']);

    return (str_contains($html, 'shout-message') && str_contains($html, 'shout-message--notice'))
        ?: substr($html, 0, 200);
});

check('the front-end markup carries no stylesheet and no inline style', function() use ($plugin) {
    $html = $plugin->frontend->render([(new Message(['title' => 'Hello']))->toWire()]);

    return (!str_contains($html, '<style') && !str_contains($html, 'style=')) ?: 'the front end has an opinion about looks';
});

check('the front-end list is empty markup when there is nothing to say', function() use ($plugin) {
    return trim($plugin->frontend->render([])) === '' ?: 'an empty list rendered something';
});

check('a site can take the markup over with its own template', function() use ($plugin) {
    $view = Craft::$app->getView();
    $path = Craft::getAlias('@templates') . '/yo/_messages.twig';

    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, '{% for m in messages %}<b class="mine">{{ m.title }}</b>{% endfor %}');

    // The override lives in the *site* templates; the fallback is in the plugin.
    $html = $plugin->frontend->render([(new Message(['title' => 'Overridden']))->toWire()]);

    unlink($path);
    @rmdir(dirname($path));

    return str_contains($html, '<b class="mine">Overridden</b>') ?: substr($html, 0, 200);
});

// ---------------------------------------------------------------------------- adoption

section('Adopting Craft’s own notices');

check('adoption does nothing on a console request, whatever the setting says', function() use ($plugin) {
    configure(['adoptCraftFlashes' => true]);

    // The guard is what is being checked: without it this call reaches `getSession()`, which a
    // console application throws from by design.
    return $plugin->adopt->drainCraftFlashes() === 0 ?: 'it tried';
});

check('adoption is off when the setting is off', function() use ($plugin) {
    configure(['adoptCraftFlashes' => false]);
    $result = $plugin->adopt->drainCraftFlashes();
    configure(['adoptCraftFlashes' => true]);

    return $result === 0 ?: 'it ran anyway';
});

// ---------------------------------------------------------------------------- the widget

section('The dashboard widget');

check('the widget lists what is waiting without taking any of it', function() use ($plugin, $testUser) {
    $plugin->messages->clearFor($testUser->id);
    sendToTestUser(['title' => 'On the dashboard']);

    $widget = new justinholtweb\yo\widgets\YoWidget();
    $html = (string)$widget->getBodyHtml();
    $stillThere = $plugin->messages->countFor($testUser->id);

    return (str_contains($html, 'On the dashboard') && $stillThere === 1)
        ?: "rendered=" . (int)str_contains($html, 'On the dashboard') . " remaining=$stillThere";
});

check('the widget draws the empty state when there is nothing', function() use ($plugin, $testUser) {
    $plugin->messages->clearFor($testUser->id);

    $html = (string)(new justinholtweb\yo\widgets\YoWidget())->getBodyHtml();

    return str_contains($html, 'yo-widget-empty') ?: substr($html, 0, 120);
});

check('the widget honours its own limit', function() use ($plugin, $testUser) {
    $plugin->messages->clearFor($testUser->id);

    for ($i = 0; $i < 5; $i++) {
        sendToTestUser(['title' => "Widget $i"]);
    }

    $widget = new justinholtweb\yo\widgets\YoWidget(['limit' => 2]);
    $html = (string)$widget->getBodyHtml();

    return substr_count($html, 'yo-widget-item"') === 2 ?: 'listed ' . substr_count($html, 'yo-widget-item"');
});

// ---------------------------------------------------------------------------- settings

section('Settings');

check('no setting is required, so a fresh install can save any one of them', function() {
    $settings = new Settings();

    foreach ($settings->rules() as $rule) {
        if (($rule[1] ?? null) === 'required') {
            return 'a required rule would block a fresh install: ' . json_encode($rule[0]);
        }
    }

    return true;
});

check('a position that is not one of the six is refused', function() {
    $settings = new Settings();
    $settings->defaultPosition = 'middle-of-nowhere';

    return $settings->validate() === false ?: 'it validated';
});

check('a class prefix that would not be a class name is refused', function() {
    $settings = new Settings();
    $settings->siteClassPrefix = '9 lives';

    return $settings->validate() === false ?: 'it validated';
});

// ---------------------------------------------------------------------------- cleanup

section('Cleanup');

check('every message this run sent is deleted', function() {
    $ids = (new Query())
        ->select('id')
        ->from(Table::MESSAGES)
        ->where(['like', 'context', RUN_TAG])
        ->column();

    if ($ids !== []) {
        Plugin::getInstance()->messages->deleteStoredWhere(['id' => $ids]);
    }

    return (new Query())->from(Table::MESSAGES)->where(['like', 'context', RUN_TAG])->exists() === false
        ?: 'messages were left behind';
});

check('the test users are deleted', function() use ($testUser, $otherUser) {
    Craft::$app->getUser()->setIdentity(null);

    return (Craft::$app->getElements()->deleteElement($testUser, true)
        && Craft::$app->getElements()->deleteElement($otherUser, true)) ?: 'a user survived';
});

check('the test group is deleted', function() use ($group) {
    return Craft::$app->getUserGroups()->deleteGroupById($group->id) ?: 'the group survived';
});

check('the original settings are restored', function() use ($plugin, $original) {
    // In memory only. `configure()` never persisted anything, so there is nothing in project
    // config to put back — and writing it here would fail on the config lock on a busy harness.
    $plugin->setSettings($original);

    return $plugin->getSettings()->toArray() == $original ?: 'settings did not round-trip';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
