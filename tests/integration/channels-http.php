<?php
/**
 * The email/webhook "Send a test" action over real HTTP: admin-only, POST only, CSRF-checked,
 * saved settings only — and the settings screen renders the new sections.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-yo/tests/integration/channels-http.php
 *
 * Makes a throwaway admin and a throwaway non-admin editor and deletes both at the end. The
 * successful email test goes to the admin's example.test address through the harness's mailer.
 * Assumes the saved Yo settings have no webhook URL (the harness default).
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\yo\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

const BASE = 'http://127.0.0.1/';
const ACTION = 'admin/actions/yo/channels/test';
const PASSWORD = 'Yo-channels-check-4417';

function client(?string $username): array
{
    $http = new Client(['base_uri' => BASE, 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $token = fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($username !== null) {
        $login = $http->post('index.php?p=actions/users/login', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['loginName' => $username, 'password' => PASSWORD, 'CRAFT_CSRF_TOKEN' => $token()],
        ]);

        if ($login->getStatusCode() !== 200) {
            throw new RuntimeException("could not sign in as $username: " . $login->getStatusCode());
        }
    }

    return [$http, $token()];
}

function postTest(Client $http, ?string $csrf, array $params): Psr\Http\Message\ResponseInterface
{
    if ($csrf !== null) {
        $params['CRAFT_CSRF_TOKEN'] = $csrf;
    }

    return $http->post(ACTION, ['headers' => ['Accept' => 'application/json'], 'form_params' => $params]);
}

function makeUser(string $username, bool $admin): User
{
    $user = User::find()->username($username)->status(null)->one() ?? new User();
    $user->username = $username;
    $user->email = "$username@example.test";
    $user->newPassword = PASSWORD;
    $user->active = true;
    $user->pending = false;
    $user->admin = $admin;
    Craft::$app->getElements()->saveElement($user) or exit('could not save ' . $username . ': ' . json_encode($user->getErrors()) . "\n");

    return $user;
}

$admin = makeUser('yo-channels-admin', true);
$editor = makeUser('yo-channels-editor', false);
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, ['accessCp', 'accessPlugin-yo']);
$savedUrl = Plugin::getInstance()->getSettings()->webhookUrlValue();

echo "\nThe test action\n";

check('the harness has no saved webhook URL (otherwise the URL-injection check proves nothing)', function() use ($savedUrl) {
    return $savedUrl === '' ?: "saved URL is $savedUrl";
});

check('signed out, a POST is turned away', function() {
    [$http, $csrf] = client(null);
    $status = postTest($http, $csrf, ['channel' => 'email'])->getStatusCode();

    return in_array($status, [302, 401, 403], true) ?: "status $status";
});

check('a non-admin with control panel and plugin access is refused with 403', function() {
    [$http, $csrf] = client('yo-channels-editor');
    $status = postTest($http, $csrf, ['channel' => 'email'])->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('an admin GET is refused — POST only', function() {
    [$http] = client('yo-channels-admin');
    $status = $http->get(ACTION . '?channel=email', ['headers' => ['Accept' => 'application/json']])->getStatusCode();

    return in_array($status, [400, 405], true) ?: "status $status";
});

check('an admin POST without a CSRF token is refused', function() {
    [$http] = client('yo-channels-admin');
    $status = postTest($http, null, ['channel' => 'email'])->getStatusCode();

    return $status === 400 ?: "status $status";
});

check('an unknown channel is a 400', function() {
    [$http, $csrf] = client('yo-channels-admin');
    $status = postTest($http, $csrf, ['channel' => 'cp'])->getStatusCode();

    return $status === 400 ?: "status $status";
});

check('a URL in the request is ignored: with none saved, the webhook test refuses', function() {
    [$http, $csrf] = client('yo-channels-admin');
    $res = postTest($http, $csrf, ['channel' => 'webhook', 'url' => 'http://169.254.169.254/latest', 'webhookUrl' => 'http://169.254.169.254/latest']);
    $body = (string)$res->getBody();

    return $res->getStatusCode() === 400 && str_contains($body, 'Save a webhook URL first') ?: $res->getStatusCode() . ' ' . substr($body, 0, 200);
});

check('an admin email test is sent to the admin', function() {
    [$http, $csrf] = client('yo-channels-admin');
    Craft::$app->getCache()->delete('yo:test:email:' . User::find()->username('yo-channels-admin')->one()->id);
    $res = postTest($http, $csrf, ['channel' => 'email']);
    $body = (string)$res->getBody();

    return $res->getStatusCode() === 200 && str_contains($body, 'yo-channels-admin@example.test') ?: $res->getStatusCode() . ' ' . substr($body, 0, 200);
});

check('a second test within thirty seconds is refused by the cooldown', function() {
    [$http, $csrf] = client('yo-channels-admin');
    $res = postTest($http, $csrf, ['channel' => 'email']);

    return $res->getStatusCode() === 400 && str_contains((string)$res->getBody(), 'moment ago') ?: $res->getStatusCode() . ' ' . substr((string)$res->getBody(), 0, 200);
});

echo "\nThe settings screen\n";

check('it renders the email and webhook sections with their test buttons, and no form of their own', function() {
    [$http] = client('yo-channels-admin');
    $res = $http->get('admin/settings/plugins/yo');
    $html = (string)$res->getBody();
    // Plugin settings are namespaced: `settings-emailChannel`, `settings[emailTypes][]`.
    $start = strpos($html, 'emailChannel"');
    $end = strpos($html, 'data-yo-test-channel="webhook"');
    $slice = $start !== false && $end !== false ? substr($html, $start, $end - $start) : '';

    return $res->getStatusCode() === 200
        && str_contains($html, 'data-yo-test-channel="email"')
        && str_contains($html, 'webhookUrl"')
        && str_contains($html, 'name="settings[emailTypes][]"')
        && $slice !== '' && !str_contains($slice, '<form')
        ?: json_encode([
            'email' => str_contains($html, 'data-yo-test-channel="email"'),
            'url' => str_contains($html, 'webhookUrl"'),
            'types' => str_contains($html, 'name="settings[emailTypes][]"'),
            'slice' => $slice !== '',
            'form' => str_contains($slice, '<form'),
            'err' => preg_match('/(Twig[^<]{0,300})/', $html, $m) ? $m[1] : null,
        ]);
});

echo "\nCleanup\n";

check('the throwaway users are deleted', function() use ($admin, $editor) {
    return Craft::$app->getElements()->deleteElement($admin, true) && Craft::$app->getElements()->deleteElement($editor, true) ?: 'a user survived';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
