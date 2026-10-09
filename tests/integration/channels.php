<?php
/**
 * Email and webhook channel checks.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-yo/tests/integration/channels.php
 *
 * Mail goes through Symfony's NullTransport and is captured from `BaseMailer::EVENT_BEFORE_SEND`.
 * Queue pushes are intercepted at `Queue::EVENT_BEFORE_PUSH` and run here by hand, so nothing this
 * script sends reaches the shared harness queue. Webhooks go to a Guzzle MockHandler: the harness
 * has no outbound network, and a real post would prove nothing about the guard anyway.
 *
 * Settings changes are in memory only. The digest marker row is put back at the end, and every
 * user, group, message and queue row this script made is deleted.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\mail\Mailer as CraftMailer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use justinholtweb\yo\channels\EmailChannel;
use justinholtweb\yo\channels\WebhookChannel;
use justinholtweb\yo\db\Table;
use justinholtweb\yo\events\EmailEvent;
use justinholtweb\yo\events\WebhookEvent;
use justinholtweb\yo\jobs\PostWebhook;
use justinholtweb\yo\jobs\SendDigest;
use justinholtweb\yo\jobs\SendEmail;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\models\Settings;
use justinholtweb\yo\Plugin;
use justinholtweb\yo\services\Digest;
use justinholtweb\yo\services\Email;
use justinholtweb\yo\services\Webhooks;
use Symfony\Component\Mailer\Transport\NullTransport;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;
use yii\queue\PushEvent;
use yii\queue\Queue;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

const RUN_TAG = 'yo-channel-checks';

$plugin = Plugin::getInstance();
$original = $plugin->getSettings()->toArray();

function configure(array $overrides): Settings
{
    $plugin = Plugin::getInstance();
    $settings = new Settings();
    $settings->setAttributes($overrides, false);
    $plugin->setSettings($settings->toArray());
    $plugin->channels->reset();

    return $plugin->getSettings();
}

// Mail: captured, never delivered.
$mails = [];
$refuseMail = false;
Craft::$app->getMailer()->setTransport(new NullTransport());
Event::on(CraftMailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $event) use (&$mails, &$refuseMail) {
    if ($refuseMail) {
        $event->isValid = false;
        return;
    }

    $to = $event->message->getTo();
    $mails[] = [
        'to' => is_array($to) ? implode(',', array_keys($to)) : (string)$to,
        'subject' => $event->message->getSubject(),
        'html' => (string)$event->message->getSymfonyEmail()->getHtmlBody(),
        'text' => (string)$event->message->getSymfonyEmail()->getTextBody(),
    ];
});

// Queue: captured, never pushed.
$jobs = [];
Event::on(Queue::class, Queue::EVENT_BEFORE_PUSH, function(PushEvent $event) use (&$jobs) {
    $jobs[] = $event->job;
    $event->handled = true;
});

function mailsTo(string $address): array
{
    global $mails;

    return array_values(array_filter($mails, fn(array $m) => str_contains($m['to'], $address)));
}

function jobsOf(string $class): array
{
    global $jobs;

    return array_values(array_filter($jobs, fn($job) => $job instanceof $class));
}

// ---------------------------------------------------------------------------- fixtures

section('Fixtures');

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$group = new craft\models\UserGroup(['name' => 'Yo channel checks ' . $suffix, 'handle' => 'yoChannels' . $suffix]);
$makeUser = function(string $name, bool $active = true) use ($suffix): User {
    $user = new User([
        'username' => "yo-ch-$name-$suffix",
        'email' => "yo-ch-$name-$suffix@example.test",
    ]);
    $user->active = $active;
    $user->pending = false;
    Craft::$app->getElements()->saveElement($user) or throw new RuntimeException(implode(', ', $user->getErrorSummary(true)));

    return $user;
};

check('a group that can reach the control panel is created', function() use ($group) {
    return Craft::$app->getUserGroups()->saveGroup($group)
        && Craft::$app->getUserPermissions()->saveGroupPermissions($group->id, ['accesscp'])
        ?: 'could not save the group';
});

$alice = $makeUser('alice');
$bob = $makeUser('bob');
$dormant = $makeUser('dormant', false);
Craft::$app->getUsers()->assignUserToGroups($alice->id, [$group->id]);
Craft::$app->getUsers()->assignUserToGroups($bob->id, [$group->id]);
Craft::$app->getUsers()->assignUserToGroups($dormant->id, [$group->id]);

check('three users: two active and one inactive, all in the group', function() use ($alice, $bob, $dormant) {
    return $alice->getStatus() === User::STATUS_ACTIVE && $dormant->getStatus() !== User::STATUS_ACTIVE ?: 'statuses: ' . $alice->getStatus() . ' / ' . $dormant->getStatus();
});

$markerBefore = (new Query())->from(Table::DIGESTS)->where(['handle' => Digest::HANDLE])->one() ?: null;
Db::delete(Table::DIGESTS, ['handle' => Digest::HANDLE]);
Db::delete(Table::EMAIL_QUEUE, ['userId' => [$alice->id, $bob->id, $dormant->id]]);

function msg(array $config = []): Message
{
    global $group;

    return new Message(array_merge([
        'title' => 'Channel check',
        'plugin' => 'yo',
        'type' => 'error',
        'audience' => 'group:' . $group->handle,
        'context' => ['tag' => RUN_TAG],
    ], $config));
}

// ---------------------------------------------------------------------------- registry

section('Registry and routing');

check('email and webhook are registered alongside the three originals', function() use ($plugin) {
    configure([]);
    $handles = array_keys($plugin->channels->getAllChannels());
    sort($handles);

    return $handles === ['cp', 'email', 'log', 'site', 'webhook'] ?: implode(', ', $handles);
});

check('switched off by default, neither wants anything — even a message naming them', function() use ($plugin) {
    configure([]);
    $handles = array_keys($plugin->channels->channelsFor(msg(['channels' => ['email', 'webhook', 'cp']])));

    return $handles === ['cp'] ?: implode(', ', $handles);
});

check('on, a routed type addressed to a group goes to email as well as the panel', function() use ($plugin) {
    configure(['emailChannel' => true, 'emailTypes' => ['error']]);
    $handles = array_keys($plugin->channels->channelsFor(msg()));

    return $handles === ['cp', 'email'] ?: implode(', ', $handles);
});

check('a type that is not routed stays out of email', function() use ($plugin) {
    configure(['emailChannel' => true, 'emailTypes' => ['error']]);

    return !in_array('email', array_keys($plugin->channels->channelsFor(msg(['type' => 'success']))), true) ?: 'success went to email';
});

check('`*` routes every type, including one nobody registered', function() {
    $settings = configure(['emailTypes' => '*']);

    return $settings->routes('email', 'error') && $settings->routes('email', 'some-plugins-type') ?: 'not routed';
});

check('an empty routing list (nothing ticked) routes nothing', function() {
    $settings = configure(['emailTypes' => '']);

    return !$settings->routes('email', 'error') ?: 'routed with nothing ticked';
});

check('a routed message for the current request is not emailed — it is on their screen already', function() use ($plugin) {
    configure(['emailChannel' => true, 'emailTypes' => '*']);

    return !in_array('email', array_keys($plugin->channels->channelsFor(msg(['audience' => 'current']))), true) ?: 'current went to email';
});

check('a sender that names email gets it, routed or not', function() use ($plugin) {
    configure(['emailChannel' => true, 'emailTypes' => []]);
    $handles = array_keys($plugin->channels->channelsFor(msg(['type' => 'tip', 'audience' => 'current', 'channels' => ['cp', 'email']])));

    return $handles === ['cp', 'email'] ?: implode(', ', $handles);
});

check('a sender that names other channels does not get email from the routing table', function() use ($plugin) {
    configure(['emailChannel' => true, 'emailTypes' => '*']);

    return array_keys($plugin->channels->channelsFor(msg(['channels' => ['cp']]))) === ['cp'] ?: 'email joined an explicit list';
});

check('the webhook wants nothing until a URL is saved, however it is routed', function() use ($plugin) {
    configure(['webhookChannel' => true, 'webhookTypes' => '*', 'webhookUrl' => '']);

    return !in_array('webhook', array_keys($plugin->channels->channelsFor(msg())), true) ?: 'webhook wanted a message with no URL';
});

check('with a URL, a routed type goes to the webhook', function() use ($plugin) {
    configure(['webhookChannel' => true, 'webhookTypes' => ['error'], 'webhookUrl' => 'https://hooks.example.com/x']);

    return in_array('webhook', array_keys($plugin->channels->channelsFor(msg())), true) ?: 'not routed';
});

check('the webhook URL resolves from an environment variable, and an unset one counts as no URL', function() {
    putenv('YO_CHECK_WEBHOOK=https://hooks.example.com/from-env');
    $_SERVER['YO_CHECK_WEBHOOK'] = 'https://hooks.example.com/from-env';
    $set = configure(['webhookUrl' => '$YO_CHECK_WEBHOOK'])->webhookUrlValue();
    $unset = configure(['webhookUrl' => '$YO_CHECK_NOT_SET'])->webhookUrlValue();

    return $set === 'https://hooks.example.com/from-env' && $unset === '' ?: "set=$set unset=$unset";
});

// ---------------------------------------------------------------------------- settings

section('Settings');

check('no new setting is required', function() {
    foreach ((new Settings())->rules() as $rule) {
        if (($rule[1] ?? null) === 'required') {
            return 'required: ' . json_encode($rule[0]);
        }
    }

    return true;
});

check('a webhook URL that is not http(s) is refused at save', function() {
    $settings = new Settings(['webhookUrl' => 'ftp://example.com/x']);

    return !$settings->validate(['webhookUrl']) ?: 'ftp validated';
});

check('an $ENV webhook URL that is not set yet saves', function() {
    $settings = new Settings(['webhookUrl' => '$YO_CHECK_NOT_SET']);

    return $settings->validate(['webhookUrl']) ?: json_encode($settings->getErrors());
});

check('a routing list that is not handle-shaped is refused', function() {
    $settings = new Settings();
    $settings->emailTypes = ['error', '<script>'];

    return !$settings->validate(['emailTypes']) ?: 'validated';
});

check('digest hour and weekday are range-checked', function() {
    $settings = new Settings(['digestHour' => 24, 'digestWeekday' => 0]);
    $settings->validate(['digestHour', 'digestWeekday']);

    return $settings->hasErrors('digestHour') && $settings->hasErrors('digestWeekday') ?: 'out-of-range values validated';
});

// ---------------------------------------------------------------------------- recipients

section('Who gets email');

check('a group message reaches its active members with an address, not the inactive one', function() use ($plugin, $alice, $bob, $dormant) {
    configure(['emailChannel' => true]);
    $ids = $plugin->email->recipientIds(msg(), [$alice->id, $bob->id, $dormant->id]);
    sort($ids);
    $want = [$alice->id, $bob->id];
    sort($want);

    return $ids === $want ?: json_encode($ids);
});

check('`everyone` means everyone who can reach the control panel — including the group', function() use ($plugin, $alice) {
    $ids = $plugin->email->recipientIds(msg(['audience' => 'everyone']), []);

    return in_array($alice->id, $ids, true) ?: 'alice missing from everyone';
});

check('`everyone` does not reach an account that cannot reach the control panel', function() use ($plugin, $suffix) {
    $outsider = new User(['username' => "yo-ch-outsider-$suffix", 'email' => "yo-ch-outsider-$suffix@example.test"]);
    $outsider->active = true;
    $outsider->pending = false;
    Craft::$app->getElements()->saveElement($outsider);
    $ids = $plugin->email->recipientIds(msg(['audience' => 'everyone']), []);
    Craft::$app->getElements()->deleteElement($outsider, true);

    return !in_array($outsider->id, $ids, true) ?: 'a front-end account got an everyone email';
});

// ---------------------------------------------------------------------------- immediate

section('Immediate email');

check('sending a routed message queues one SendEmail job, not an inline send', function() use ($alice, $bob) {
    global $jobs, $mails;
    $jobs = [];
    $mails = [];
    configure(['emailChannel' => true, 'emailTypes' => ['error'], 'emailMode' => 'immediate']);
    msg(['title' => 'Import failed', 'body' => 'Line 3 <b>broke</b>.'])
        ->action('Open the log', url: '/admin/transport/logs/88')
        ->action('Retry', action: 'transport/imports/retry')
        ->send();

    $send = jobsOf(SendEmail::class);
    $ids = $send[0]->userIds ?? [];
    sort($ids);
    $want = [$alice->id, $bob->id];
    sort($want);

    return count($send) === 1 && $mails === [] && $ids === $want ?: count($send) . ' jobs, ' . count($mails) . ' mails, ids ' . json_encode($ids);
});

check('the job emails each person separately, never one To: line', function() use ($alice, $bob) {
    global $mails;
    $mails = [];
    jobsOf(SendEmail::class)[0]->execute(null);

    return count($mails) === 2 && count(mailsTo($alice->email)) === 1 && count(mailsTo($bob->email)) === 1
        && !str_contains($mails[0]['to'], ',')
        ?: json_encode(array_column($mails, 'to'));
});

check('the email escapes the body, keeps link buttons absolute, and drops POST-action buttons', function() use ($alice) {
    $mail = mailsTo($alice->email)[0];

    return str_contains($mail['html'], 'Line 3 &lt;b&gt;broke&lt;/b&gt;.')
        && str_contains($mail['html'], 'Open the log')
        && preg_match('#href="https?://[^"]+/admin/transport/logs/88"#', $mail['html']) === 1
        && !str_contains($mail['html'], 'Retry')
        && str_contains($mail['text'], 'Line 3 <b>broke</b>.')
        && str_contains($mail['subject'], 'Import failed')
        ?: substr($mail['html'], 0, 600);
});

check('EVENT_BEFORE_SEND can retitle and cancel', function() use ($plugin, $alice) {
    global $mails;
    $mails = [];
    $handler = function(EmailEvent $e) {
        $e->subject = 'Retitled';
    };
    Event::on(Email::class, Email::EVENT_BEFORE_SEND, $handler);
    $plugin->email->sendMessage(msg(), [$alice->id]);
    Event::off(Email::class, Email::EVENT_BEFORE_SEND, $handler);
    $retitled = ($mails[0]['subject'] ?? '') === 'Retitled';

    $mails = [];
    $cancel = fn(EmailEvent $e) => $e->isValid = false;
    Event::on(Email::class, Email::EVENT_BEFORE_SEND, $cancel);
    $sent = $plugin->email->sendMessage(msg(), [$alice->id]);
    Event::off(Email::class, Email::EVENT_BEFORE_SEND, $cancel);

    return $retitled && $sent === 0 && $mails === [] ?: 'retitled=' . var_export($retitled, true) . ' sent=' . $sent;
});

check('an account deactivated between send and job gets nothing', function() use ($plugin, $dormant) {
    global $mails;
    $mails = [];

    return $plugin->email->sendMessage(msg(), [$dormant->id]) === 0 && $mails === [] ?: 'mailed an inactive account';
});

check('a message to the current user from a console run (no identity) emails nobody', function() use ($plugin) {
    global $jobs;
    $jobs = [];
    Craft::$app->getUser()->setIdentity(null);
    configure(['emailChannel' => true, 'emailTypes' => '*']);
    msg(['audience' => 'current', 'channels' => ['email']])->send();

    return jobsOf(SendEmail::class) === [] ?: 'queued an email for nobody';
});

// ---------------------------------------------------------------------------- digest

section('Digest');

$digest = $plugin->digest;
$tz = new DateTimeZone(Craft::$app->getTimeZone());
$at = fn(string $when) => new DateTimeImmutable($when, $tz);

function waiting(int $userId): int
{
    return (int)(new Query())->from(Table::EMAIL_QUEUE)->where(['userId' => $userId])->count();
}

check('in digest mode a routed message is filed per person instead of queued as a job', function() use ($alice, $bob, $dormant) {
    global $jobs;
    $jobs = [];
    configure(['emailChannel' => true, 'emailTypes' => '*', 'emailMode' => 'digest', 'digestFrequency' => 'daily', 'digestHour' => 8]);
    msg(['title' => 'First'])->send();
    msg(['title' => 'Second', 'type' => 'warning'])->action('See it', url: 'https://example.com/see')->send();

    return jobsOf(SendEmail::class) === [] && waiting($alice->id) === 2 && waiting($bob->id) === 2 && waiting($dormant->id) === 0
        ?: 'jobs=' . count(jobsOf(SendEmail::class)) . ' alice=' . waiting($alice->id) . ' dormant=' . waiting($dormant->id);
});

check('a dedupe key replaces the earlier waiting copy rather than stacking', function() use ($alice) {
    msg(['title' => 'Progress 10%', 'dedupeKey' => 'check-progress'])->send();
    msg(['title' => 'Progress 90%', 'dedupeKey' => 'check-progress'])->send();
    $titles = (new Query())->select('title')->from(Table::EMAIL_QUEUE)->where(['userId' => $alice->id])->column();

    return count($titles) === 3 && in_array('Progress 90%', $titles, true) && !in_array('Progress 10%', $titles, true) ?: json_encode($titles);
});

check('weekly periods use the ISO year: 2027-01-01 is 2026-W53', function() use ($digest, $at) {
    configure(['emailChannel' => true, 'emailMode' => 'digest', 'digestFrequency' => 'weekly']);
    $key = $digest->periodKey($at('2027-01-01 12:00'));
    configure(['emailChannel' => true, 'emailTypes' => '*', 'emailMode' => 'digest', 'digestFrequency' => 'daily', 'digestHour' => 8]);

    return $key === '2026-W53' ?: $key;
});

check('before the hour, the digest is not due and nothing is sent', function() use ($digest, $at) {
    global $mails;
    $mails = [];
    $result = $digest->run($at('2030-03-04 07:59'));

    return $result === Digest::RESULT_NOT_DUE && $mails === [] ?: $result;
});

check('a failed send gives the period back and keeps every row', function() use ($digest, $at, $alice) {
    global $mails, $refuseMail;
    $mails = [];
    $refuseMail = true;
    $result = $digest->run($at('2030-03-04 08:05'));
    $refuseMail = false;

    return $result === Digest::RESULT_FAILED && $digest->state()['period'] === null && waiting($alice->id) === 3
        ?: "$result, period " . var_export($digest->state()['period'], true) . ', waiting ' . waiting($alice->id);
});

check('at or after the hour, each person gets one digest and their rows are consumed', function() use ($digest, $at, $alice, $bob) {
    global $mails;
    $mails = [];
    $result = $digest->run($at('2030-03-04 08:20'));
    $aliceMail = mailsTo($alice->email);

    return $result === Digest::RESULT_SENT
        && count($aliceMail) === 1 && count(mailsTo($bob->email)) === 1
        && str_contains($aliceMail[0]['subject'], '3 messages')
        && str_contains($aliceMail[0]['html'], 'Progress 90%') && str_contains($aliceMail[0]['html'], 'https://example.com/see')
        && waiting($alice->id) === 0 && waiting($bob->id) === 0
        && $digest->state()['period'] === '2030-03-04'
        ?: "$result, " . count($mails) . ' mails, subject ' . ($aliceMail[0]['subject'] ?? '—') . ', waiting ' . waiting($alice->id);
});

check('a second run in the same period sends nothing', function() use ($digest, $at) {
    global $mails;
    $mails = [];
    msg(['title' => 'Late arrival'])->send();
    $result = $digest->run($at('2030-03-04 09:00'));

    return $result === Digest::RESULT_ALREADY_SENT && $mails === [] ?: $result;
});

check('the next day, what arrived late goes out', function() use ($digest, $at, $alice) {
    global $mails;
    $mails = [];
    $result = $digest->run($at('2030-03-05 08:00'));

    return $result === Digest::RESULT_SENT && str_contains(mailsTo($alice->email)[0]['html'] ?? '', 'Late arrival') ?: $result;
});

check('a day with nothing waiting records the period and sends no email', function() use ($digest, $at) {
    global $mails;
    $mails = [];
    $result = $digest->run($at('2030-03-06 08:00'));

    return $result === Digest::RESULT_NOTHING_NEW && $mails === [] && $digest->state()['period'] === '2030-03-06' ?: $result;
});

check('a handler that cancels one person’s digest drops their rows; the other still gets theirs', function() use ($digest, $at, $alice, $bob) {
    global $mails;
    $mails = [];
    msg(['title' => 'For the cancel check'])->send();
    $handler = function(EmailEvent $e) use ($alice) {
        if ($e->isDigest && $e->userId === $alice->id) {
            $e->isValid = false;
        }
    };
    Event::on(Email::class, Email::EVENT_BEFORE_SEND, $handler);
    $result = $digest->run($at('2030-03-07 08:00'));
    Event::off(Email::class, Email::EVENT_BEFORE_SEND, $handler);

    return $result === Digest::RESULT_SENT && mailsTo($alice->email) === [] && count(mailsTo($bob->email)) === 1
        && waiting($alice->id) === 0 ?: "$result, alice mails " . count(mailsTo($alice->email)) . ', waiting ' . waiting($alice->id);
});

check('switched off, the digest reports disabled and leaves the rows', function() use ($digest, $at, $alice) {
    msg(['title' => 'While off'])->send();
    configure(['emailChannel' => true, 'emailTypes' => '*', 'emailMode' => 'immediate']);
    $result = $digest->run($at('2030-03-08 08:00'));

    return $result === Digest::RESULT_DISABLED ?: $result;
});

check('a test digest goes to the one person, is marked a test, and consumes nothing', function() use ($plugin, $alice) {
    global $mails;
    $mails = [];
    configure(['emailChannel' => true, 'emailTypes' => '*', 'emailMode' => 'digest']);
    $before = waiting($alice->id);
    $periodBefore = $plugin->digest->state()['period'];
    $sent = $plugin->email->sendTest($alice);

    return $sent === 1 && count($mails) === 1 && str_contains($mails[0]['subject'], '[Test]')
        && waiting($alice->id) === $before && $plugin->digest->state()['period'] === $periodBefore
        ?: "sent=$sent mails=" . count($mails) . ' waiting ' . waiting($alice->id) . "/$before";
});

check('an immediate-mode test is a sample message to the one person', function() use ($plugin, $alice) {
    global $mails;
    $mails = [];
    configure(['emailChannel' => true, 'emailMode' => 'immediate']);
    $sent = $plugin->email->sendTest($alice);

    return $sent === 1 && str_contains($mails[0]['subject'] ?? '', '[Test]') && $mails[0]['to'] === $alice->email ?: "sent=$sent";
});

check('the web fallback queues SendDigest once when due, and not again within five minutes', function() use ($plugin, $at) {
    global $jobs;
    $jobs = [];
    configure(['emailChannel' => true, 'emailMode' => 'digest', 'digestWebTrigger' => true, 'digestHour' => 8]);
    Craft::$app->getCache()->delete('yo:digest:checked');
    Craft::$app->getCache()->delete('yo:digest:queued:2030-03-09');
    $first = $plugin->digest->queueIfDue($at('2030-03-09 10:00'));
    $second = $plugin->digest->queueIfDue($at('2030-03-09 10:01'));
    Craft::$app->getCache()->delete('yo:digest:checked');

    return $first && !$second && count(jobsOf(SendDigest::class)) === 1 ?: 'first=' . var_export($first, true) . ' second=' . var_export($second, true);
});

check('the web fallback does nothing with the web trigger off', function() use ($plugin, $at) {
    configure(['emailChannel' => true, 'emailMode' => 'digest', 'digestWebTrigger' => false]);
    Craft::$app->getCache()->delete('yo:digest:checked');

    return $plugin->digest->queueIfDue($at('2030-03-10 10:00')) === false ?: 'queued anyway';
});

check('a deleted account takes its waiting rows with it', function() use ($makeUser) {
    $temp = $makeUser('temp');
    configure(['emailChannel' => true, 'emailTypes' => '*', 'emailMode' => 'digest']);
    (new Message(['title' => 'x', 'plugin' => 'yo', 'type' => 'error', 'audience' => 'user:' . $temp->id, 'context' => ['tag' => RUN_TAG]]))->send();
    $before = waiting($temp->id);
    Craft::$app->getElements()->deleteElement($temp, true);

    return $before === 1 && waiting($temp->id) === 0 ?: "before=$before after=" . waiting($temp->id);
});

// ---------------------------------------------------------------------------- webhook

section('Webhook — the SSRF guard');

check('loopback, private, link-local (metadata) and IPv4-mapped hosts are refused', function() use ($plugin) {
    configure(['webhookChannel' => true]);

    foreach (['http://127.0.0.1/x', 'http://10.1.2.3/x', 'http://169.254.169.254/latest/meta-data', 'http://[::ffff:127.0.0.1]/x', 'http://localhost/x'] as $url) {
        if (!is_string($plugin->webhooks->target($url))) {
            return "$url was allowed";
        }
    }

    return true;
});

check('non-http schemes and URLs with credentials are refused', function() use ($plugin) {
    foreach (['ftp://93.184.216.34/x', 'file:///etc/passwd', 'gopher://93.184.216.34/', 'https://user:pass@93.184.216.34/x'] as $url) {
        if (!is_string($plugin->webhooks->target($url))) {
            return "$url was allowed";
        }
    }

    return true;
});

check('a public address is allowed and pinned', function() use ($plugin) {
    $target = $plugin->webhooks->target('https://93.184.216.34:8443/hook');

    return is_array($target) && $target['addresses'] === ['93.184.216.34'] && $target['port'] === 8443 ?: json_encode($target);
});

check('allowPrivateWebhookHosts (config file only) lets a LAN host through, unpinned', function() use ($plugin) {
    configure(['webhookChannel' => true, 'allowPrivateWebhookHosts' => true]);
    $target = $plugin->webhooks->target('http://10.1.2.3/x');
    $ftp = $plugin->webhooks->target('ftp://10.1.2.3/x');
    configure(['webhookChannel' => true]);

    return is_array($target) && $target['addresses'] === [] && is_string($ftp) ?: json_encode([$target, $ftp]);
});

section('Webhook — posting');

$history = [];
$mock = new MockHandler();
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$plugin->webhooks->client = new Client(['handler' => $stack]);

check('a refused URL is never requested', function() use ($plugin, $mock) {
    global $history;
    $history = [];
    $result = $plugin->webhooks->post('http://127.0.0.1/x', ['text' => 'x']);

    return is_string($result) && $history === [] ?: 'requested: ' . var_export($result, true);
});

check('a post is JSON, unsigned without a secret, no redirects, pinned with CURLOPT_RESOLVE', function() use ($plugin, $mock) {
    global $history;
    $history = [];
    configure(['webhookChannel' => true, 'webhookSecret' => '']);
    $mock->append(new Psr7Response(200));
    $result = $plugin->webhooks->post('https://93.184.216.34/hook', ['text' => 'hello']);
    $request = $history[0]['request'] ?? null;
    $options = $history[0]['options'] ?? [];

    return $result === true
        && $request?->getHeaderLine('Content-Type') === 'application/json'
        && (string)$request?->getBody() === '{"text":"hello"}'
        && !$request?->hasHeader('X-Yo-Signature')
        && ($options['allow_redirects'] ?? null) === false
        && ($options['curl'][CURLOPT_RESOLVE][0] ?? '') === '93.184.216.34:443:93.184.216.34'
        ?: json_encode([$result, $options['allow_redirects'] ?? null, $options['curl'] ?? null]);
});

check('with a secret (from $ENV), the post carries a verifiable HMAC of timestamp.body', function() use ($plugin, $mock) {
    global $history;
    $history = [];
    $_SERVER['YO_CHECK_SECRET'] = 's3cret-for-checks';
    putenv('YO_CHECK_SECRET=s3cret-for-checks');
    configure(['webhookChannel' => true, 'webhookSecret' => '$YO_CHECK_SECRET']);
    $mock->append(new Psr7Response(204));
    $plugin->webhooks->post('https://93.184.216.34/hook', ['text' => 'signed']);
    $request = $history[0]['request'];
    $ts = $request->getHeaderLine('X-Yo-Timestamp');
    $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $request->getBody(), 's3cret-for-checks');

    return $ts !== '' && hash_equals($expected, $request->getHeaderLine('X-Yo-Signature')) ?: 'signature did not verify';
});

check('a redirect is reported as a failure, not followed', function() use ($plugin, $mock) {
    global $history;
    $history = [];
    $mock->append(new Psr7Response(302, ['Location' => 'http://169.254.169.254/']));
    $result = $plugin->webhooks->post('https://93.184.216.34/hook', ['text' => 'x']);

    return $result === 'HTTP 302' && count($history) === 1 ?: var_export($result, true);
});

section('Webhook — the channel');

check('sending a routed message queues one PostWebhook carrying the payload but not the URL', function() use ($plugin) {
    global $jobs;
    $jobs = [];
    configure(['webhookChannel' => true, 'webhookTypes' => ['error'], 'webhookUrl' => 'https://93.184.216.34/secret-path-123', 'webhookFormat' => 'slack']);
    msg(['title' => 'Sync broke'])->send();
    $post = jobsOf(PostWebhook::class);
    $serialised = serialize($post[0] ?? null);

    return count($post) === 1 && str_contains($post[0]->payload['text'] ?? '', 'Sync broke') && !str_contains($serialised, 'secret-path-123')
        ?: count($post) . ' jobs';
});

check('the job posts to the saved URL; a non-2xx throws so the queue retries, up to three attempts', function() use ($plugin, $mock) {
    global $history;
    $history = [];
    $job = jobsOf(PostWebhook::class)[0];
    $mock->append(new Psr7Response(200));
    $job->execute(null);
    $ok = count($history) === 1 && (string)$history[0]['request']->getUri() === 'https://93.184.216.34/secret-path-123';

    $mock->append(new Psr7Response(500));
    $threw = false;
    try {
        $job->execute(null);
    } catch (RuntimeException) {
        $threw = true;
    }

    return $ok && $threw && $job->canRetry(1, null) && !$job->canRetry(3, null) ?: 'ok=' . var_export($ok, true) . ' threw=' . var_export($threw, true);
});

check('a job whose saved URL is now refused gives up quietly instead of retrying', function() use ($plugin) {
    global $history;
    $history = [];
    configure(['webhookChannel' => true, 'webhookUrl' => 'http://127.0.0.1/x']);
    (new PostWebhook(['payload' => ['text' => 'x']]))->execute(null);

    return $history === [] ?: 'requested a refused URL';
});

check('Slack payloads escape mrkdwn and carry link buttons', function() use ($plugin) {
    $m = $plugin->webhooks->describe(msg(['title' => 'A <b> & c', 'body' => '<@here>'])->action('Open', url: 'https://example.com/a|b'));
    $p = $plugin->webhooks->payload('slack', $m);
    $section = $p['blocks'][0]['text']['text'];
    $context = $p['blocks'][1]['elements'][0]['text'];

    return str_contains($section, 'A &lt;b&gt; &amp; c') && str_contains($section, '&lt;@here&gt;')
        && str_contains($context, '<https://example.com/a%7Cb|Open>') ?: $section . ' / ' . $context;
});

check('Teams payloads are an Adaptive Card coloured by type', function() use ($plugin) {
    $p = $plugin->webhooks->payload('teams', $plugin->webhooks->describe(msg()));
    $card = $p['attachments'][0]['content'] ?? [];

    return ($card['type'] ?? '') === 'AdaptiveCard' && ($card['body'][0]['color'] ?? '') === 'Attention' ?: json_encode($card);
});

check('JSON payloads carry the uid, type and sender for your own receiver', function() use ($plugin) {
    $message = msg();
    $p = $plugin->webhooks->payload('json', $plugin->webhooks->describe($message));

    return $p['event'] === 'yo.message' && $p['uid'] === $message->uid && $p['type'] === 'error' && $p['plugin'] === 'yo' ?: json_encode($p);
});

check('EVENT_BEFORE_POST can add to the payload or hold a message back', function() use ($plugin) {
    global $jobs;
    $jobs = [];
    configure(['webhookChannel' => true, 'webhookTypes' => '*', 'webhookUrl' => 'https://93.184.216.34/x', 'webhookFormat' => 'json']);
    $add = function(WebhookEvent $e) {
        $e->payload['env'] = 'checks';
    };
    Event::on(Webhooks::class, Webhooks::EVENT_BEFORE_POST, $add);
    msg()->send();
    Event::off(Webhooks::class, Webhooks::EVENT_BEFORE_POST, $add);
    $added = (jobsOf(PostWebhook::class)[0]->payload['env'] ?? null) === 'checks';

    $jobs = [];
    $hold = fn(WebhookEvent $e) => $e->isValid = false;
    Event::on(Webhooks::class, Webhooks::EVENT_BEFORE_POST, $hold);
    msg()->send();
    Event::off(Webhooks::class, Webhooks::EVENT_BEFORE_POST, $hold);

    return $added && jobsOf(PostWebhook::class) === [] ?: 'added=' . var_export($added, true);
});

check('the webhook test posts a sample to the saved URL', function() use ($plugin, $mock) {
    global $history;
    $history = [];
    configure(['webhookChannel' => true, 'webhookUrl' => 'https://93.184.216.34/test', 'webhookFormat' => 'slack']);
    $mock->append(new Psr7Response(200));
    $result = $plugin->webhooks->sendTest();

    return $result === true && str_contains((string)$history[0]['request']->getBody(), 'test') ?: var_export($result, true);
});

// ---------------------------------------------------------------------------- console

section('Console');

check('yo/digest/status and yo/digest/send run', function() {
    $out = [];
    exec('php craft yo/digest/status 2>&1', $out, $status);
    $text = implode("\n", $out);
    $out2 = [];
    exec('php craft yo/digest/send 2>&1', $out2, $status2);

    return $status === 0 && str_contains($text, 'Next due') && $status2 === 0 ?: "$status: $text / $status2: " . implode(' ', $out2);
});

// ---------------------------------------------------------------------------- cleanup

section('Cleanup');

check('waiting rows, messages, users and the group are deleted; the marker is restored', function() use ($alice, $bob, $dormant, $group, $markerBefore, $plugin) {
    Db::delete(Table::EMAIL_QUEUE, ['userId' => [$alice->id, $bob->id, $dormant->id]]);
    $ids = (new Query())->select('id')->from(Table::MESSAGES)->where(['like', 'context', RUN_TAG])->column();

    if ($ids !== []) {
        $plugin->messages->deleteStoredWhere(['id' => $ids]);
    }

    foreach ([$alice, $bob, $dormant] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    Craft::$app->getUserGroups()->deleteGroupById($group->id);
    Db::delete(Table::DIGESTS, ['handle' => Digest::HANDLE]);

    if ($markerBefore !== null) {
        unset($markerBefore['id']);
        Db::insert(Table::DIGESTS, $markerBefore);
    }

    return !(new Query())->from(Table::MESSAGES)->where(['like', 'context', RUN_TAG])->exists() ?: 'messages left behind';
});

check('the original settings are restored', function() use ($plugin, $original) {
    $plugin->setSettings($original);
    $plugin->webhooks->client = null;

    return true;
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
