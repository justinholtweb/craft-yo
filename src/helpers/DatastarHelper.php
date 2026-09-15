<?php

namespace justinholtweb\yo\helpers;

use Craft;
use yii\web\Response;

/**
 * DataStar Helper
 *
 * Formats responses as DataStar 1.0 Server-Sent Events.
 *
 * DataStar 1.0 uses a line-based SSE wire format (not JSON-wrapped):
 *
 *   event: datastar-patch-elements
 *   data: selector #yo-messages
 *   data: mode inner
 *   data: elements <div>…</div>
 *
 *   event: datastar-patch-signals
 *   data: signals {"yoCount":3}
 *
 * @see https://data-star.dev/reference/sse_events
 */
class DatastarHelper
{
    /**
     * Build a DataStar SSE response.
     *
     * `$events` accepts two keys, both optional:
     *
     * - `elements`: one element patch, or a list of them. Each patch is an array of:
     *     - `html` (string, required) — the HTML fragment; may be multi-line.
     *     - `selector` (string) — CSS selector of the target. Omit to morph by element id.
     *     - `mode` (string) — outer (default) | inner | replace | prepend | append |
     *       before | after | remove.
     * - `signals`: signal name => value pairs to patch into the page.
     *
     * @param array $events
     */
    public static function response(array $events): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        $data = '';

        if (isset($events['elements'])) {
            $patches = $events['elements'];
            // Allow a single patch to be passed directly.
            if (isset($patches['html'])) {
                $patches = [$patches];
            }

            foreach ($patches as $patch) {
                $data .= self::patchElements($patch);
            }
        }

        if (!empty($events['signals'])) {
            $data .= self::patchSignals($events['signals']);
        }

        $response->data = $data;

        return $response;
    }

    /**
     * Format a single `datastar-patch-elements` event.
     */
    private static function patchElements(array $patch): string
    {
        $out = "event: datastar-patch-elements\n";

        if (!empty($patch['selector'])) {
            $out .= 'data: selector ' . $patch['selector'] . "\n";
        }

        if (!empty($patch['mode'])) {
            $out .= 'data: mode ' . $patch['mode'] . "\n";
        }

        // Each line of the fragment must be sent on its own `data: elements` line.
        $html = $patch['html'] ?? '';
        foreach (explode("\n", $html) as $line) {
            $out .= 'data: elements ' . $line . "\n";
        }

        return $out . "\n";
    }

    /**
     * Format a `datastar-patch-signals` event.
     */
    private static function patchSignals(array $signals): string
    {
        return "event: datastar-patch-signals\n"
            . 'data: signals ' . json_encode($signals) . "\n\n";
    }
}
