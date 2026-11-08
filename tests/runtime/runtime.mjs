/**
 * The front-end script, run for real in Node against a stub DOM.
 *
 *     node tests/runtime/runtime.mjs                                   # on the Mac
 *     ddev exec node /var/www/craft-yo/tests/runtime/runtime.mjs       # or in the harness
 *
 * The PHP suites can only read yo-site.js as text, and checks.sh only sees what the server sends.
 * This loads the file the three ways a site can — Yo's own injection (inline `window.YoSiteConfig`
 * first), a CommonJS bundle, and an ES-module bundle via yo-site.mjs — and watches what it does to
 * the messages on the page and what it posts.
 *
 * `YO_DIST=/some/dir` points it at another copy of the two files (to prove a check fails against
 * an older yo-site.js).
 */
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import vm from 'node:vm';

const dist = process.env.YO_DIST || join(dirname(fileURLToPath(import.meta.url)), '../../src/web/assets/dist');
const source = readFileSync(join(dist, 'yo-site.js'), 'utf8');

let passed = 0;
let failed = 0;

async function check(label, test) {
    try {
        const result = await test();

        if (result === true) {
            passed++;
            console.log(`  ✓ ${label}`);
            return;
        }

        failed++;
        console.log(`  ✗ ${label}\n    ${typeof result === 'string' ? result : 'returned ' + JSON.stringify(result)}`);
    } catch (e) {
        failed++;
        console.log(`  ✗ ${label}\n    ${e.stack}`);
    }
}

/** Let fetch promises settle. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

/**
 * A page: the config attribute (if any), the container (if any) with some messages in it, and
 * everything the script tries to fetch.
 */
function page({ config = null, container = true, messages = [{ sticky: false }], session = 'from-session' } = {}) {
    const requests = [];
    const timers = [];

    const els = messages.map((m, i) => {
        const classes = [];
        const attrs = {
            'data-yo-sticky': m.sticky ? '1' : '0',
            'data-yo-dismiss-url': `/actions/yo/stream/dismiss?uid=m${i}&channel=site`,
        };

        return {
            classes,
            removed: false,
            classList: { add: (c) => classes.push(c) },
            getAttribute: (a) => (a in attrs ? attrs[a] : null),
            querySelectorAll: () => [],
            remove() { this.removed = true; },
        };
    });

    const box = container
        ? { querySelectorAll: (s) => (s === '[data-yo-message]' ? els : []) }
        : null;

    const document = {
        readyState: 'complete',
        querySelector(selector) {
            if (selector === '[data-yo-site-config]' && config !== null) {
                return { getAttribute: (a) => (a === 'data-yo-site-config' ? config : null) };
            }
            return null;
        },
        getElementById: (id) => (id === 'yo-site-messages' ? box : null),
        addEventListener() {},
    };

    const window = {
        document,
        setTimeout(fn, ms) { timers.push({ fn, ms }); return timers.length; },
        fetch(url, init = {}) {
            requests.push({ url, method: init.method || 'GET', headers: init.headers || {} });
            return Promise.resolve({ json: () => Promise.resolve({ csrfTokenValue: session }) });
        },
    };

    /** Fire every pending timer — the auto-dismiss, then the removal it schedules. */
    const flush = () => {
        while (timers.length) {
            timers.splice(0).forEach((t) => t.fn());
        }
    };

    const posts = () => requests.filter((r) => r.method === 'POST');
    const sessionInfo = () => requests.filter((r) => r.url === '/actions/users/session-info');

    return { window, document, els, requests, timers, flush, posts, sessionInfo };
}

class MutationObserver {
    observe() {}
}

/** Run yo-site.js as a classic script, as the browser does. */
function load(p, module = undefined) {
    const context = { window: p.window, document: p.document, fetch: p.window.fetch, MutationObserver, FormData: class {} };
    if (module) {
        context.module = module;
    }
    vm.runInNewContext(source, context);
    return p.window.YoSite;
}

/** What `{{ craft.yo.runtimeConfig() }}` renders — never the token. */
const runtimeConfig = (overrides = {}) => JSON.stringify({
    csrfTokenName: 'CRAFT_CSRF_TOKEN',
    prefix: 'shout',
    autoDismissAfter: 4,
    sessionInfoUrl: '/actions/users/session-info',
    ...overrides,
});

console.log('\nThe injected path (Yo loads the file)');

await check('with window.YoSiteConfig written first, it starts by itself, as it always has', async () => {
    const p = page();
    p.window.YoSiteConfig = { csrfTokenName: 'CRAFT_CSRF_TOKEN', csrfTokenValue: 'inline', prefix: 'yo', autoDismissAfter: 6 };
    load(p);

    const timer = p.timers[0];
    p.flush();
    await settle();

    return timer?.ms === 6000
        && p.els[0].classes.includes('yo--leaving')
        && p.els[0].removed
        && p.posts().length === 1
        && p.posts()[0].url.includes('dismiss')
        && p.posts()[0].headers['X-CSRF-Token'] === 'inline'
        && p.sessionInfo().length === 0
        || `timer=${JSON.stringify(timer)} classes=${p.els[0].classes} requests=${JSON.stringify(p.requests)}`;
});

await check('a sticky message is left alone', () => {
    const p = page({ messages: [{ sticky: true }] });
    p.window.YoSiteConfig = { csrfTokenValue: 'inline', autoDismissAfter: 6 };
    load(p);

    return p.timers.length === 0 || `${p.timers.length} timers`;
});

console.log('\nThe bundled path (the site imports it)');

await check('a bundled copy waits for YoSite.start() and does nothing before it', () => {
    const p = page({ config: runtimeConfig() });
    const YoSite = load(p);

    return typeof YoSite?.start === 'function' && p.timers.length === 0 && p.requests.length === 0
        || `timers=${p.timers.length} requests=${p.requests.length}`;
});

await check('YoSite.start() with no arguments takes the prefix and delay from data-yo-site-config', async () => {
    const p = page({ config: runtimeConfig() });
    load(p).start();

    const timer = p.timers[0];
    p.flush();
    await settle();

    return timer?.ms === 4000 && p.els[0].classes.includes('shout--leaving')
        || `timer=${JSON.stringify(timer)} classes=${p.els[0].classes}`;
});

await check('the CSRF token comes from users/session-info, not the page, and the post carries it', async () => {
    const p = page({ config: runtimeConfig() });
    load(p).start();
    await settle();

    const fetched = p.window.YoSiteConfig?.csrfTokenValue;
    p.flush();
    await settle();

    return p.sessionInfo().length === 1
        && p.sessionInfo()[0].headers.Accept === 'application/json'
        // The templates' DataStar click handlers read exactly this.
        && fetched === 'from-session'
        && p.posts()[0]?.headers['X-CSRF-Token'] === 'from-session'
        || `sessionInfo=${p.sessionInfo().length} fetched=${fetched} posts=${JSON.stringify(p.posts())}`;
});

await check('a post that beats the token fetch waits for it rather than going without', async () => {
    const p = page({ config: runtimeConfig({ autoDismissAfter: 1 }) });
    load(p).start();
    p.flush();          // the timer fires before the session-info promise has settled
    await settle();

    return p.posts().length === 1 && p.posts()[0].headers['X-CSRF-Token'] === 'from-session'
        || JSON.stringify(p.posts());
});

await check('options passed to YoSite.start() win over the page', () => {
    const p = page({ config: runtimeConfig() });
    load(p).start({ prefix: 'mine', autoDismissAfter: 2, csrfTokenValue: 'given' });
    p.flush();

    return p.timers.length === 0
        && p.els[0].classes.includes('mine--leaving')
        && p.sessionInfo().length === 0
        && p.posts()[0]?.headers['X-CSRF-Token'] === 'given'
        || `classes=${p.els[0].classes} requests=${JSON.stringify(p.requests)}`;
});

await check('a page with no container never asks for a token', async () => {
    const p = page({ config: runtimeConfig(), container: false });
    load(p).start();
    await settle();

    return p.requests.length === 0 || JSON.stringify(p.requests);
});

await check('malformed data-yo-site-config is ignored rather than thrown', () => {
    const p = page({ config: '{not json' });
    load(p).start();
    p.flush();

    // No delay configured anywhere, so nothing is dismissed; and no endpoint, so nothing is asked.
    return p.timers.length === 0 && p.requests.length === 0 || `timers=${p.timers.length}`;
});

await check('a CommonJS bundler gets the same object as window.YoSite', () => {
    const p = page();
    const module = { exports: {} };
    const YoSite = load(p, module);

    return typeof YoSite?.start === 'function' && module.exports === YoSite;
});

console.log('\nLoaded twice');

await check('the first copy wins and a bundler gets the live instance — one timer, not two', () => {
    const p = page({ config: runtimeConfig() });
    const first = load(p);
    first.start();

    const module = { exports: {} };
    const second = load(p, module);
    second.start();

    return second === first && module.exports === first && p.timers.length === 1
        || `same=${second === first} timers=${p.timers.length}`;
});

await check('bundled first, then Yo injects its copy: the injected copy still starts it', () => {
    const p = page();
    load(p);                                                       // bundled, not started yet
    p.window.YoSiteConfig = { csrfTokenValue: 'inline', autoDismissAfter: 3 };
    load(p);                                                       // Yo's inline config + script

    return p.timers.length === 1 && p.timers[0].ms === 3000 || `timers=${JSON.stringify(p.timers)}`;
});

console.log('\nThe ES module');

await check('yo-site.mjs default-exports the script and still sets window.YoSite', async () => {
    const mjs = join(dist, 'yo-site.mjs');

    if (!existsSync(mjs)) {
        return 'yo-site.mjs is missing';
    }

    const p = page({ config: runtimeConfig() });
    const realFetch = globalThis.fetch;
    Object.assign(globalThis, { window: p.window, document: p.document, MutationObserver, fetch: p.window.fetch });

    try {
        const { default: YoSite } = await import(pathToFileURL(mjs).href);
        YoSite.start({ csrfTokenValue: 'given' });
        p.flush();

        return YoSite === p.window.YoSite
            && p.els[0].classes.includes('shout--leaving')
            && p.posts()[0]?.headers['X-CSRF-Token'] === 'given'
            || `classes=${p.els[0].classes} requests=${JSON.stringify(p.requests)}`;
    } finally {
        delete globalThis.window;
        delete globalThis.document;
        delete globalThis.MutationObserver;
        globalThis.fetch = realFetch;
    }
});

await check('yo-site.mjs wraps yo-site.js and imports no DataStar of its own', () => {
    const mjs = join(dist, 'yo-site.mjs');

    if (!existsSync(mjs)) {
        return 'yo-site.mjs is missing';
    }

    const imports = readFileSync(mjs, 'utf8').match(/^import .*$/gm) || [];

    return imports.length === 1 && imports[0] === "import './yo-site.js';" || JSON.stringify(imports);
});

await check('yo-site.js is still an ES5 classic script that never touches DataStar', () => {
    // `new vm.Script` compiles as a classic script; an `export` or `import` would be a SyntaxError.
    new vm.Script(source);
    const code = source.replace(/\/\*[\s\S]*?\*\/|\/\/.*$/gm, '');

    if (/\b(const|let|class)\s|=>/.test(code)) {
        return 'ES2015+ syntax in yo-site.js';
    }

    return !/datastar/i.test(code) || 'yo-site.js refers to DataStar';
});

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed === 0 ? 0 : 1);
