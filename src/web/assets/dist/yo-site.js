/* Yo — the front-end half.
 *
 * There is no stylesheet, and there is almost no script. DataStar attributes on the container do
 * the polling and the dismissing; this file exists for the two things that are neither state nor
 * markup: taking a message off the page when its time is up, and posting a button that named an
 * action of its own.
 *
 * Nothing here writes a style, adds a class of its own beyond `is-leaving`, or measures anything.
 * The markup is the site owner's. */
(function() {
    'use strict';

    var config = window.YoSiteConfig || {};
    var prefix = config.prefix || 'yo';
    var timers = new WeakMap();

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': config.csrfTokenValue, 'Accept': 'text/event-stream' },
            body: body || null,
            credentials: 'same-origin'
        });
    }

    function wire(container) {
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

        wire(container);

        // The list is replaced wholesale by every server patch, so watching it is the only way to
        // catch messages that arrive after the page did.
        new MutationObserver(function() { wire(container); })
            .observe(container, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
