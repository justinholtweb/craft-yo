/* Yo — the front-end half.
 *
 * There is no stylesheet, and there is almost no script. DataStar attributes on the container do
 * the polling and the dismissing; this file exists for the two things that are neither state nor
 * markup: taking a message off the page when its time is up, and posting a button that named an
 * action of its own.
 *
 * Nothing here writes a style, adds a class of its own beyond `is-leaving`, or measures anything.
 * The markup is the site owner's.
 *
 * Two ways in, one file. Yo loads it itself (with `window.YoSiteConfig` written inline first) and
 * it starts on its own. Or a site bundles it — `import YoSite from './yo-site.mjs'` — and calls
 * `YoSite.start()`, which reads `{{ craft.yo.runtimeConfig() }}` off the page. Classic script,
 * ES5, no dependencies: this file never calls DataStar, it only wires markup DataStar renders, so
 * it neither imports nor needs a handle on whichever copy of DataStar the page runs. */
(function(window, document) {
    'use strict';

    // Loaded twice — injected by Yo *and* bundled by the site — the first copy wins, so there is
    // only ever one observer and one timer per message. A bundler still gets the live instance.
    if (window.YoSite) {
        if (typeof module === 'object' && module && module.exports) {
            module.exports = window.YoSite;
        }

        // The injected copy arriving second still has to start it, as it always has.
        if (isPlainObject(window.YoSiteConfig)) {
            window.YoSite.start();
        }

        return;
    }

    var config = {};
    var started = false;
    var timers = new WeakMap();
    var tokenRequest = null;

    // ── configuration ────────────────────────────────────────────────────────────────────────

    /**
     * A plain object, not a DOM node: `<form id="YoSiteConfig">`, or two `<a id="YoSiteConfig">`,
     * in user content makes `window.YoSiteConfig` an element or a collection (DOM clobbering).
     */
    function isPlainObject(value) {
        return Object.prototype.toString.call(value) === '[object Object]';
    }

    function configure(options) {
        if (!isPlainObject(options)) {
            return;
        }

        for (var key in options) {
            if (Object.prototype.hasOwnProperty.call(options, key)) {
                config[key] = options[key];
            }
        }
    }

    /**
     * What `{{ craft.yo.runtimeConfig() }}` writes into the page, for a site that bundles this file
     * itself. Site-specific, never visitor-specific, so the page stays cacheable. Malformed JSON is
     * ignored rather than thrown: a broken attribute must not take the rest of the bundle down.
     *
     * Read off `<body>` or `<html>` and nowhere else. Sanitisers that keep `data-*` attributes put
     * user content in the page, and a `data-yo-site-config` of its own placed earlier in the DOM
     * would otherwise be the first one found — and the page's config.
     */
    function pageConfig() {
        var nodes = [document.body, document.documentElement];

        for (var i = 0; i < nodes.length; i++) {
            var value = nodes[i] && nodes[i].getAttribute ? nodes[i].getAttribute('data-yo-site-config') : null;

            if (value === null || value === undefined) {
                continue;
            }

            try {
                var parsed = JSON.parse(value);

                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (e) {
                return {};
            }
        }

        return {};
    }

    /**
     * Whether a URL points at this page's own origin. Anything the script sends a CSRF token to,
     * or takes one from, has to. Fails closed: not a string, unparseable, or no way to tell → no.
     */
    function sameOrigin(url) {
        if (typeof url !== 'string') {
            return false;
        }

        // What a URL parser discards anyway: leading C0 controls and spaces, and any tab or newline.
        url = url.replace(/^[\u0000-\u0020]+/, '').replace(/[\t\n\r]/g, '');

        if (url === '') {
            return false;
        }

        var here = window.location;

        if (typeof URL === 'function' && here && here.href) {
            try {
                var there = new URL(url, here.href);

                return there.protocol === here.protocol && there.host === here.host;
            } catch (e) {
                return false;
            }
        }

        // No parser: a path on this host and nothing else — not `//host`, not `/\host`, which
        // browsers read as `//host`.
        return url.charAt(0) === '/' && url.charAt(1) !== '/' && url.charAt(1) !== '\\';
    }

    /**
     * The visitor's CSRF token. Yo's inline config carries it; the page attribute deliberately
     * does not (it would make every page visitor-specific), so a bundled runtime asks Craft's own
     * `users/session-info` once, and only on a page that has a container to post from.
     */
    function token() {
        if (config.csrfTokenValue || !config.sessionInfoUrl || typeof fetch !== 'function') {
            return null;
        }

        if (!tokenRequest) {
            tokenRequest = fetch(config.sessionInfoUrl, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function(response) {
                return response.json();
            }).then(function(info) {
                if (info && info.csrfTokenValue && !config.csrfTokenValue) {
                    config.csrfTokenValue = info.csrfTokenValue;
                }
            }, function() {
                // Try again on the next post rather than never.
                tokenRequest = null;
            });
        }

        return tokenRequest;
    }

    // ── behaviour ────────────────────────────────────────────────────────────────────────────

    function post(url, body) {
        // The token rides in a header, so it goes wherever the URL does. Not off this origin.
        if (!sameOrigin(url)) {
            return null;
        }

        function send() {
            return fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': config.csrfTokenValue, 'Accept': 'text/event-stream' },
                body: body || null,
                credentials: 'same-origin'
            });
        }

        var pending = token();

        return pending ? pending.then(send) : send();
    }

    function wire(container) {
        var prefix = config.prefix || 'yo';

        container.querySelectorAll('[data-yo-message]').forEach(function(el) {
            if (!timers.has(el) && el.getAttribute('data-yo-sticky') !== '1') {
                var after = parseInt(config.autoDismissAfter, 10);

                if (after > 0) {
                    timers.set(el, window.setTimeout(function() {
                        el.classList.add(prefix + '--leaving');
                        var url = el.getAttribute('data-yo-dismiss-url');
                        if (url) post(url);
                        window.setTimeout(function() { el.remove(); }, 200);
                    }, after * 1000));
                }
            }

            el.querySelectorAll('[data-yo-post]').forEach(function(button) {
                if (button.dataset.yoWired) return;
                button.dataset.yoWired = '1';

                button.addEventListener('click', function() {
                    var params = {};

                    try {
                        params = JSON.parse(button.getAttribute('data-yo-params') || '{}');
                    } catch (e) {
                        params = {};
                    }

                    var body = new FormData();
                    Object.keys(params).forEach(function(key) { body.append(key, params[key]); });

                    post(button.getAttribute('data-yo-post'), body);
                });
            });
        });
    }

    function boot() {
        var container = document.getElementById('yo-site-messages');
        if (!container) return;

        // Fetch the token now, not at the first click: the templates' DataStar handlers read
        // `window.YoSiteConfig.csrfTokenValue` synchronously when they fire.
        token();

        wire(container);

        // The list is replaced wholesale by every server patch, so watching it is the only way to
        // catch messages that arrive after the page did.
        new MutationObserver(function() { wire(container); })
            .observe(container, { childList: true, subtree: true });
    }

    // ── public API ───────────────────────────────────────────────────────────────────────────

    var YoSite = {
        /**
         * Start wiring the container. Yo calls this itself when it loads the file; a site that
         * bundles it calls it with no arguments and the page's `data-yo-site-config` supplies the
         * rest. Anything passed here wins over the page. Only the first call counts.
         */
        start: function(options) {
            if (started) {
                return YoSite;
            }

            started = true;

            configure(window.YoSiteConfig);
            configure(pageConfig());

            // The token is fetched from here, so the page — or anything that can write into it —
            // must not be able to point it elsewhere. What the site passes to start() is trusted.
            if (config.sessionInfoUrl !== undefined && !sameOrigin(config.sessionInfoUrl)) {
                delete config.sessionInfoUrl;
            }

            configure(options);

            // The message templates' DataStar handlers send `window.YoSiteConfig.csrfTokenValue`,
            // so this is the object they have to find — including once a fetched token lands.
            window.YoSiteConfig = config;

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot);
            } else {
                boot();
            }

            return YoSite;
        }
    };

    window.YoSite = YoSite;

    // For a site that bundles the runtime: a CommonJS bundler gets the same object as the global.
    // ES-module bundlers use yo-site.mjs instead, which wraps this file rather than duplicating it.
    if (typeof module === 'object' && module && module.exports) {
        module.exports = YoSite;
    }

    // Yo loaded this file and wrote `window.YoSiteConfig` first: start, exactly as before. A
    // bundled copy has no inline config and waits for `YoSite.start()`.
    if (isPlainObject(window.YoSiteConfig)) {
        YoSite.start();
    }
})(window, document);
