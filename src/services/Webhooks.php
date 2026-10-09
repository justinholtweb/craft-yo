<?php

namespace justinholtweb\yo\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use justinholtweb\yo\events\WebhookEvent;
use justinholtweb\yo\helpers\Ip;
use justinholtweb\yo\jobs\PostWebhook;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\models\MessageAction;
use justinholtweb\yo\models\Settings;
use justinholtweb\yo\Plugin;
use Throwable;

/**
 * The webhook channel's sender: Slack, Microsoft Teams, or plain signed JSON for your own receiver.
 *
 * The SSRF rules are the family's, copied from craft-erpy's failure alerts and not to be loosened
 * when this file is touched:
 *
 * 1. `http` and `https` only, with no credentials in the URL.
 * 2. Every address the host resolves to must be public ({@see Ip::resolvePublic()}).
 * 3. The connection is pinned to those addresses with `CURLOPT_RESOLVE`, so a second lookup at
 *    connect time cannot rebind the host somewhere private.
 * 4. Redirects are never followed.
 *
 * The URL only ever comes from the saved settings, which only an admin can change. Nothing that
 * sends a message, and no request parameter, can point the webhook anywhere.
 */
class Webhooks extends Component
{
    /** @see WebhookEvent Change the body, or set `isValid` to false to hold this one back. */
    public const EVENT_BEFORE_POST = 'beforePost';

    /** Swappable for tests. The real one is a curl-only Guzzle client, so the pin applies. */
    public ?ClientInterface $client = null;

    /**
     * Queues a post of one message. Called by the webhook channel.
     */
    public function dispatch(Message $message): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->webhookUrlValue() === '') {
            return false;
        }

        $event = new WebhookEvent([
            'message' => $message,
            'payload' => $this->payload($settings->webhookFormat, $this->describe($message)),
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_POST)) {
            $this->trigger(self::EVENT_BEFORE_POST, $event);

            if (!$event->isValid) {
                return false;
            }
        }

        Craft::$app->getQueue()->push(new PostWebhook(['payload' => $event->payload]));

        return true;
    }

    /**
     * Posts a sample to the saved webhook, for the settings screen's "Send a test" button.
     *
     * @return bool|string|null True on a 2xx, the reason otherwise, null when no URL is saved.
     */
    public function sendTest(): bool|string|null
    {
        $settings = Plugin::getInstance()->getSettings();
        $url = $settings->webhookUrlValue();

        if ($url === '') {
            return null;
        }

        $message = new Message([
            'title' => Craft::t('yo', 'Yo! This is a test.'),
            'body' => Craft::t('yo', 'If you can read this, messages routed to the webhook will reach you here.'),
            'type' => 'notice',
            'plugin' => 'yo',
        ]);

        return $this->post($url, $this->payload($settings->webhookFormat, $this->describe($message)));
    }

    /**
     * What every format draws from.
     *
     * @return array{uid:string,title:string,body:string,type:string,typeLabel:string,color:string,plugin:string,site:string,url:string,links:array<int,array{label:string,url:string}>}
     */
    public function describe(Message $message): array
    {
        $type = $message->getMessageType();
        $links = self::links($message);

        return [
            'uid' => $message->uid,
            'title' => $message->title,
            'body' => $message->body,
            'type' => $type->handle,
            'typeLabel' => $type->label,
            'color' => $type->color,
            'plugin' => $message->plugin,
            'site' => Craft::$app->getSites()->getPrimarySite()->getName(),
            'url' => $links[0]['url'] ?? UrlHelper::cpUrl(),
            'links' => $links,
        ];
    }

    /**
     * The body in the receiver's own shape.
     *
     * @param array{uid:string,title:string,body:string,type:string,typeLabel:string,color:string,plugin:string,site:string,url:string,links:array<int,array{label:string,url:string}>} $m
     * @return array<string, mixed>
     */
    public function payload(string $format, array $m): array
    {
        $from = $m['plugin'] !== '' ? $m['plugin'] : 'yo';

        return match ($format) {
            Settings::WEBHOOK_TEAMS => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => array_values(array_filter([
                            ['type' => 'TextBlock', 'size' => 'Large', 'weight' => 'Bolder', 'color' => self::teamsColor($m['type']), 'text' => $m['title'], 'wrap' => true],
                            $m['body'] !== '' ? ['type' => 'TextBlock', 'text' => $m['body'], 'wrap' => true] : null,
                            ['type' => 'FactSet', 'facts' => [
                                ['title' => Craft::t('yo', 'Site'), 'value' => $m['site']],
                                ['title' => Craft::t('yo', 'From'), 'value' => $from],
                                ['title' => Craft::t('yo', 'Type'), 'value' => $m['typeLabel']],
                            ]],
                        ])),
                        'actions' => array_map(
                            static fn(array $link) => ['type' => 'Action.OpenUrl', 'title' => $link['label'], 'url' => $link['url']],
                            $m['links'] !== [] ? $m['links'] : [['label' => Craft::t('yo', 'Open in Craft'), 'url' => $m['url']]],
                        ),
                    ],
                ]],
            ],
            Settings::WEBHOOK_JSON => [
                'event' => 'yo.message',
                'uid' => $m['uid'],
                'type' => $m['type'],
                'plugin' => $m['plugin'],
                'site' => $m['site'],
                'title' => $m['title'],
                'body' => $m['body'],
                'url' => $m['url'],
                'links' => $m['links'],
                'at' => (new DateTime('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
            default => [
                'text' => $m['typeLabel'] . ': ' . $m['title'] . ($m['body'] !== '' ? "\n" . $m['body'] : ''),
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . self::slack($m['title']) . '*' . ($m['body'] !== '' ? "\n" . self::slack($m['body']) : '')]],
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => implode(' · ', array_merge(
                        [self::slack($m['typeLabel']), self::slack($from), self::slack($m['site'])],
                        array_map(
                            static fn(array $link) => '<' . str_replace(['|', '<', '>'], ['%7C', '%3C', '%3E'], $link['url']) . '|' . self::slack($link['label']) . '>',
                            $m['links'] !== [] ? $m['links'] : [['label' => Craft::t('yo', 'Open in Craft'), 'url' => $m['url']]],
                        ),
                    ))]]],
                ],
            ],
        };
    }

    /**
     * Where a webhook may be sent, or why it may not.
     *
     * `allowPrivateWebhookHosts` (config file only) skips the public-address check and the pin;
     * the scheme, no-credentials and no-redirect rules still hold.
     *
     * @return array{host:string,port:int,addresses:string[]}|string the pinned target, or the refusal
     */
    public function target(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)(is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = (string)(is_array($parts) ? ($parts['host'] ?? '') : '');

        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return Craft::t('yo', 'Only http:// and https:// webhook URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return Craft::t('yo', 'Webhook URLs may not carry a username or password.');
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (Plugin::getInstance()->getSettings()->allowPrivateWebhookHosts) {
            return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => []];
        }

        $addresses = Ip::resolvePublic($host);

        if ($addresses === []) {
            return Craft::t('yo', 'That host doesn’t resolve, or resolves to a private, loopback or link-local address. Webhooks only go to public addresses.');
        }

        return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => $addresses];
    }

    /**
     * POSTs the payload. True on a 2xx, otherwise the reason — never an exception.
     *
     * @param array<string, mixed> $payload
     */
    public function post(string $url, array $payload): bool|string
    {
        $target = $this->target($url);

        if (is_string($target)) {
            return $target;
        }

        $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Yo for Craft CMS'];
        $secret = Plugin::getInstance()->getSettings()->webhookSecretValue();

        if ($secret !== '') {
            $timestamp = (string)time();
            $headers['X-Yo-Timestamp'] = $timestamp;
            $headers['X-Yo-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }

        $options = [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
        ];

        if ($target['addresses'] !== []) {
            // One entry per host:port, addresses comma-joined — one entry per address would leave
            // only the last one pinned.
            $options['curl'][CURLOPT_RESOLVE] = [sprintf(
                '%s:%d:%s',
                $target['host'],
                $target['port'],
                implode(',', array_map(static fn(string $ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $target['addresses'])),
            )];
        }

        try {
            $client = $this->client ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
            $response = $client->request('POST', $url, $options);
            $status = $response->getStatusCode();

            return $status >= 200 && $status < 300 ? true : 'HTTP ' . $status;
        } catch (Throwable $e) {
            // A Slack or Teams webhook URL is itself the credential; the reason ends up in logs.
            return str_replace($url, $target['host'], $e->getMessage());
        }
    }

    /**
     * A message's link buttons, made absolute. Buttons that POST a Craft action cannot work from a
     * chat message or an inbox, so they are left out; so is anything that is not http(s).
     *
     * @return array<int, array{label:string,url:string}>
     */
    public static function links(Message $message): array
    {
        $links = [];

        foreach ($message->actions as $action) {
            if (!$action instanceof MessageAction || $action->url === null || $action->url === '') {
                continue;
            }

            $url = UrlHelper::isAbsoluteUrl($action->url) ? $action->url : UrlHelper::siteUrl($action->url);
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

            if (!in_array($scheme, ['http', 'https'], true)) {
                continue;
            }

            $links[] = ['label' => $action->label, 'url' => $url];
        }

        return $links;
    }

    /** Slack's mrkdwn needs exactly these three escaped, and nothing else. */
    private static function slack(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private static function teamsColor(string $type): string
    {
        return match ($type) {
            'error' => 'Attention',
            'warning' => 'Warning',
            'success' => 'Good',
            default => 'Default',
        };
    }
}
