/* Yo — the parts of the floating panel that are not state.
 *
 * DataStar owns open/closed, the count, and swapping the message list. This file owns the three
 * things DataStar has no opinion about: a pointer gesture (dragging), the clock (relative times
 * and auto-dismiss), and catching Craft's own Ajax notifications on their way to the screen.
 *
 * It re-runs its per-message work from a MutationObserver rather than after each fetch, because
 * the list can be replaced by any patch from any action, and hooking every one of them is how a
 * message ends up with no timestamp on it. */
(function() {
    'use strict';

    var config = window.YoConfig || {};
    var panel = null;
    var list = null;

    var ANCHORS = [
        'top-left', 'top-center', 'top-right',
        'bottom-left', 'bottom-center', 'bottom-right'
    ];

    // ---------------------------------------------------------------- helpers

    function post(url, body) {
        var headers = { 'X-CSRF-Token': config.csrfTokenValue, 'Accept': 'text/event-stream' };

        return fetch(url, {
            method: 'POST',
            headers: headers,
            body: body || null,
            credentials: 'same-origin'
        });
    }

    /* Craft's action URLs may already carry `p=admin/...` depending on the site's URL settings,
       so a query string cannot be assumed either way. */
    function withParam(url, key, value) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + key + '=' + encodeURIComponent(value);
    }

    function ago(seconds) {
        var d = Math.max(0, Math.floor(Date.now() / 1000) - seconds);

        if (d < 45) return 'just now';
        if (d < 90) return 'a minute ago';
        if (d < 3600) return Math.round(d / 60) + ' min ago';
        if (d < 7200) return 'an hour ago';
        if (d < 86400) return Math.round(d / 3600) + ' hours ago';
        if (d < 172800) return 'yesterday';
        return Math.round(d / 86400) + ' days ago';
    }

    // ---------------------------------------------------------------- per-message work

    var dismissTimers = new WeakMap();

    function decorate() {
        if (!list) return;

        list.querySelectorAll('[data-yo-time]').forEach(function(el) {
            var stamp = parseInt(el.getAttribute('data-yo-time'), 10);
            if (stamp) el.textContent = ago(stamp);
        });

        list.querySelectorAll('[data-yo-message]').forEach(function(el) {
            armAutoDismiss(el);
            armCustomAction(el);
        });
    }

    /* A non-sticky message leaves on its own. The row is taken off the screen first and the
       receipt filed afterwards — the person has stopped looking at it either way, and waiting for
       a round trip to animate it out makes a fast panel feel like a slow one. */
    function armAutoDismiss(el) {
        if (dismissTimers.has(el)) return;
        if (el.getAttribute('data-yo-sticky') === '1') return;

        var after = parseInt(panel.getAttribute('data-yo-auto-dismiss'), 10);
        if (!after || after <= 0) return;

        var timer = window.setTimeout(function() {
            var uid = el.getAttribute('data-yo-uid');
            el.classList.add('yo-message--leaving');
            window.setTimeout(function() { el.remove(); updateCount(); }, 180);
            post(withParam(config.dismissUrl, 'uid', uid));
        }, after * 1000);

        dismissTimers.set(el, timer);

        // Hovering is somebody reading it. Cancelling for good rather than restarting the clock:
        // a message you deliberately looked at should not vanish the moment you look away.
        el.addEventListener('mouseenter', function() {
            window.clearTimeout(dismissTimers.get(el));
            dismissTimers.set(el, 0);
        });
    }

    /* A button that named a Craft action of its own posts to it directly. Yo's own endpoint has
       already been told about the press by DataStar; this is the work the sender asked for. */
    function armCustomAction(el) {
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
    }

    function updateCount() {
        var badge = panel.querySelector('[data-yo-count]');
        var n = list ? list.querySelectorAll('[data-yo-message]').length : 0;

        if (badge) badge.textContent = String(n);
    }

    /* The alien reacts once when something new lands, then settles. */
    function shout() {
        var host = panel.querySelector('[data-yo-mascot] .yo-alien');
        if (!host) return;

        host.classList.add('yo-alien--talking');
        window.setTimeout(function() { host.classList.remove('yo-alien--talking'); }, 1100);
    }

    // ---------------------------------------------------------------- dragging

    function nearestAnchor(x, y) {
        var vertical = y < window.innerHeight / 2 ? 'top' : 'bottom';
        var third = window.innerWidth / 3;
        var horizontal = x < third ? 'left' : (x > third * 2 ? 'right' : 'center');

        return vertical + '-' + horizontal;
    }

    function setAnchor(anchor) {
        ANCHORS.forEach(function(a) { panel.classList.remove('yo-panel--' + a); });
        panel.classList.add('yo-panel--' + anchor);
        panel.classList.remove('yo-panel--free');
        panel.style.top = panel.style.left = panel.style.right = panel.style.bottom = '';
        panel.setAttribute('data-yo-position', anchor);

        panel.querySelectorAll('[data-yo-position-choice]').forEach(function(button) {
            button.classList.toggle('yo-move__spot--on', button.getAttribute('data-yo-position-choice') === anchor);
        });
    }

    function enableDrag() {
        var handle = panel.querySelector('[data-yo-drag]');
        if (!handle) return;

        var dragging = false;
        var offsetX = 0;
        var offsetY = 0;

        handle.addEventListener('pointerdown', function(event) {
            // Not on the buttons living in the same bar.
            if (event.target.closest('button')) return;

            var box = panel.getBoundingClientRect();
            dragging = true;
            offsetX = event.clientX - box.left;
            offsetY = event.clientY - box.top;

            panel.classList.add('yo-panel--dragging', 'yo-panel--free');
            panel.style.right = panel.style.bottom = 'auto';
            handle.setPointerCapture(event.pointerId);
        });

        handle.addEventListener('pointermove', function(event) {
            if (!dragging) return;

            panel.style.left = (event.clientX - offsetX) + 'px';
            panel.style.top = (event.clientY - offsetY) + 'px';
        });

        handle.addEventListener('pointerup', function(event) {
            if (!dragging) return;
            dragging = false;
            panel.classList.remove('yo-panel--dragging');

            var box = panel.getBoundingClientRect();
            var anchor = nearestAnchor(box.left + box.width / 2, box.top + box.height / 2);

            setAnchor(anchor);
            post(withParam(config.positionUrl, 'position', anchor));
            handle.releasePointerCapture(event.pointerId);
        });
    }

    function enableMoveMenu() {
        var toggle = panel.querySelector('[data-yo-move-toggle]');
        var menu = panel.querySelector('[data-yo-move-menu]');
        if (!toggle || !menu) return;

        toggle.addEventListener('click', function() { menu.hidden = !menu.hidden; });

        menu.addEventListener('click', function(event) {
            var button = event.target.closest('[data-yo-position-choice]');
            if (!button) return;

            // The POST is DataStar's; this is the part it cannot know about — moving the panel
            // is a class change on an element the patch never touches.
            setAnchor(button.getAttribute('data-yo-position-choice'));
            menu.hidden = true;
        });
    }

    // ---------------------------------------------------------------- adopting Craft's Ajax notices

    /* Craft's flash notices are drained server-side (see services\Adopt). The ones returned in an
       Ajax response body never touch the session, so they are caught here instead, on the one
       function every one of them goes through. The original is still called when Yo is not
       adopting, and always if anything in here throws — a plugin that swallows the control
       panel's own notifications is worse than one that shows them twice. */
    function adoptNotifications() {
        if (!config.adoptCraftFlashes) return;
        if (!window.Craft || !window.Craft.cp || typeof window.Craft.cp.displayNotification !== 'function') return;

        var original = window.Craft.cp.displayNotification.bind(window.Craft.cp);

        window.Craft.cp.displayNotification = function(type, message, settings) {
            try {
                if (!panel) return original(type, message, settings);

                var known = { notice: 1, success: 1, error: 1, warning: 1 };
                var body = (settings && settings.details) || '';

                appendLocal(known[type] ? type : 'notice', message, body);
                return null;
            } catch (e) {
                return original(type, message, settings);
            }
        };
    }

    /* Draws a message the server has not been told about. Used only for adopted Ajax notices —
       everything else arrives as rendered HTML from the server. The markup is deliberately the
       same shape as `_panel-messages.twig`; if that template changes, this needs to follow. */
    function appendLocal(type, title, body) {
        if (!list) return;

        var empty = list.querySelector('[data-yo-empty]');
        if (empty) empty.remove();

        var ul = list.querySelector('.yo-list');

        if (!ul) {
            ul = document.createElement('ul');
            ul.className = 'yo-list';
            list.prepend(ul);
        }

        var li = document.createElement('li');
        li.className = 'yo-message yo-message--' + type;
        li.setAttribute('data-yo-message', '');
        li.setAttribute('data-yo-uid', 'local-' + Math.random().toString(36).slice(2));
        li.setAttribute('data-yo-sticky', type === 'error' ? '1' : '0');

        var titleEl = document.createElement('p');
        titleEl.className = 'yo-message__title';
        titleEl.textContent = title;

        var wrap = document.createElement('div');
        wrap.className = 'yo-message__body';
        wrap.appendChild(titleEl);

        if (body) {
            var bodyEl = document.createElement('p');
            bodyEl.className = 'yo-message__text';
            bodyEl.textContent = body;
            wrap.appendChild(bodyEl);
        }

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'yo-message__dismiss';
        close.innerHTML = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="m4 4 8 8M12 4l-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>';
        close.addEventListener('click', function() { li.remove(); updateCount(); });

        li.appendChild(wrap);
        li.appendChild(close);
        ul.prepend(li);

        panel.setAttribute('data-yo-has', '1');
        updateCount();
        shout();
        armAutoDismiss(li);
    }

    // ---------------------------------------------------------------- boot

    function boot() {
        panel = document.getElementById('yo-panel');
        if (!panel) return;

        list = document.getElementById('yo-messages');

        enableDrag();
        enableMoveMenu();
        adoptNotifications();
        decorate();

        if (list) {
            var seen = list.querySelectorAll('[data-yo-message]').length;

            new MutationObserver(function() {
                decorate();
                updateCount();

                var now = list.querySelectorAll('[data-yo-message]').length;
                if (now > seen) shout();
                seen = now;
            }).observe(list, { childList: true, subtree: true });

            if (seen > 0) shout();
        }

        // The clock only matters while somebody is looking at it.
        window.setInterval(decorate, 60000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
