<?php
/**
 * Seeds (or clears) the demo messages the marketing screenshots are taken from.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-yo/tests/fixtures/demo.php seed
 *     ddev exec php /var/www/craft-yo/tests/fixtures/demo.php clear
 *
 * The senders are real plugins in the family, because a screenshot whose messages all come from
 * "yo" proves the opposite of the pitch. Everything is addressed to `everyone` so whoever the
 * screenshot harness signs in as can see it.
 *
 * Clear it when you are done. The harness is shared, and five permanent messages addressed at
 * everybody turn up in every other plugin's screenshots.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;
use justinholtweb\yo\widgets\YoWidget;

const SENDERS = ['shipper', 'transport', 'redpen', 'microscope', 'alarmclock'];

$mode = $argv[1] ?? 'seed';
$plugin = Plugin::getInstance();

$cleared = $plugin->messages->deleteStoredWhere(['plugin' => SENDERS]);

if ($mode === 'clear') {
    echo "Cleared $cleared demo messages.\n";
    exit(0);
}

$seed = [
    ['shipper', 'error', 'ShipStation would not answer', 'Timed out after 30 seconds. 14 shipments are still queued and nothing was lost.', [['Try again', 'retry', true], ['Open the log', 'log', false]]],
    ['transport', 'success', 'Import finished', '412 entries in, 3 skipped. The skipped three had no title.', [['Review the log', 'log', false]]],
    ['redpen', 'warning', 'Three entries went live without a meta description', 'Spring Launch, Field Notes 04 and About the Studio.', [['Show me', 'show', true]]],
    ['microscope', 'tip', 'Your slowest template is _partials/nav.twig', 'It runs 41 element queries on every page. Eager-loading the nav would take that to two.', []],
    ['alarmclock', 'notice', 'Two entries are scheduled for 09:00 tomorrow', '', []],
];

foreach ($seed as [$from, $type, $title, $body, $actions]) {
    $message = new Message([
        'title' => $title,
        'body' => $body,
        'type' => $type,
        'plugin' => $from,
        'audience' => Message::AUDIENCE_EVERYONE,
        'ttl' => 0,
    ]);

    foreach ($actions as [$label, $key, $primary]) {
        $message->action($label, url: '/admin', key: $key, primary: $primary);
    }

    $message->send();
    echo "  sent: $title\n";
}

// Dismissals are per person and would hide the demo from whoever ran this before.
Craft::$app->getDb()->createCommand()->delete(justinholtweb\yo\db\Table::DELIVERIES)->execute();

$admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('admin');

if ($admin !== null) {
    Craft::$app->getUser()->setIdentity($admin);
    $dashboard = Craft::$app->getDashboard();
    $has = false;

    foreach ($dashboard->getAllWidgets() as $widget) {
        $has = $has || $widget instanceof YoWidget;
    }

    if (!$has) {
        echo $dashboard->saveWidget(new YoWidget(['colspan' => 2])) ? "  widget added\n" : "  widget failed\n";
    }
}

echo "Seeded 5 demo messages.\n";
