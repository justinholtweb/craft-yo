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
 * A page: the config attribute (if any) and the element it is on, the container (if any) with some
 * messages in it, and everything the script tries to fetch.
 *
 * `configOn` is `body`, `html` or `footer` (any other element, after the content). `forged` is a
 * `data-yo-site-config` somebody got into the page content — after `<html>` and `<body>` in
 * document order, before a footer. `origin` gives the page a `location` and the script a `URL`
 * parser; without it the script has neither and takes its fallback.
 */
function page({
    config = null,
    configOn = 'body',
    forged = null,
    container = true,
    messages = [{ sticky: false }],
    session = 'from-session',
    origin = null,
} = {}) {
    const requests = [];
    const timers = [];

    const els = messages.map((m, i) => {
        const classes = [];
        const attrs = {
            'data-yo-sticky': m.sticky ? '1' : '0',
            'data-yo-dismiss-url': m.dismissUrl ?? `/actions/yo/stream/dismiss?uid=m${i}&channel=site`,
        };

        // A button with an action of its own: `data-yo-post`, wired by the script.
        const buttons = (m.posts || []).map((url) => {
            const listeners = [];
            return {
                dataset: {},
                getAttribute: (a) => (a === 'data-yo-post' ? url : null),
                addEventListener: (event, fn) => listeners.push(fn),
                click: () => listeners.forEach((fn) => fn()),
            };
        });

        return {
            classes,
            buttons,
            removed: false,
            classList: { add: (c) => classes.push(c) },
            getAttribute: (a) => (a in attrs ? attrs[a] : null),
            querySelectorAll: (s) => (s === '[data-yo-post]' ? buttons : []),
            remove() { this.removed = true; },
        };
    });

    const box = container
        ? { querySelectorAll: (s) => (s === '[data-yo-message]' ? els : []) }
        : null;

    const element = (value) => ({ getAttribute: (a) => (a === 'data-yo-site-config' ? value : null) });
    const html = element(configOn === 'html' ? config : null);
    const body = element(configOn === 'body' ? config : null);

    const document = {
        readyState: 'complete',
        documentElement: html,
        body,
        // Document order, as the browser answers it.
        querySelector(selector) {
            if (selector !== '[data-yo-site-config]') {
                return null;
            }
            if (config !== null && configOn === 'html') return html;
            if (config !== null && configOn === 'body') return body;
            if (forged !== null) return element(forged);
            if (config !== null && configOn === 'footer') return element(config);
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

    if (origin) {
        window.location = new URL('/some/page', origin);
    }

    /** Fire every pending timer — the auto-dismiss, then the removal it schedules. */
    const flush = () => {
        while (timers.length) {
            timers.splice(0).forEach((t) => t.fn());
        }
    };

    const posts = () => requests.filter((r) => r.method === 'POST');
    const sessionInfo = () => requests.filter((r) => r.url === '/actions/users/session-info');
    const gets = () => requests.filter((r) => r.method === 'GET');

    return { window, document, els, requests, timers, flush, posts, sessionInfo, gets, parser: !!origin };
}

class MutationObserver {
    observe() {}
}

/** Run yo-site.js as a classic script, as the browser does. */
function load(p, module = undefined) {
    const context = { window: p.window, document: p.document, fetch: p.window.fetch, MutationObserver, FormData: class { append() {} } };
    if (p.parser) {
        context.URL = URL;
    }
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

console.log('\nWhere the page config is read from');

/** A config somebody got into the page content: a token fetch somewhere else, and their own token. */
const forgery = JSON.stringify({ sessionInfoUrl: 'https://evil.example/steal', csrfTokenValue: 'forged', prefix: 'pwned', autoDismissAfter: 1 });

await check('config on <body> is used, and a forged one in the content after it is not', async () => {
    const p = page({ config: runtimeConfig(), configOn: 'body', forged: forgery });
    load(p).start();
    p.flush();
    await settle();

    return p.els[0].classes.includes('shout--leaving')
        && p.sessionInfo().length === 1
        && p.posts()[0]?.headers['X-CSRF-Token'] === 'from-session'
        || `classes=${p.els[0].classes} requests=${JSON.stringify(p.requests)}`;
});

await check('config on <html> is used', () => {
    const p = page({ config: runtimeConfig(), configOn: 'html', forged: forgery });
    load(p).start();
    p.flush();

    return p.els[0].classes.includes('shout--leaving') || `classes=${p.els[0].classes}`;
});

await check('data-yo-site-config on any other element is ignored, even the real one', async () => {
    const p = page({ config: runtimeConfig(), configOn: 'footer' });
    load(p).start();
    p.flush();
    await settle();

    // No delay and no endpoint from anywhere: nothing is dismissed and nothing is fetched.
    return p.timers.length === 0 && p.requests.length === 0 && !p.els[0].classes.length
        || `timers=${p.timers.length} classes=${p.els[0].classes} requests=${JSON.stringify(p.requests)}`;
});

await check('the injected path: a forged attribute cannot replace the inline token or config', async () => {
    const p = page({ forged: forgery });
    p.window.YoSiteConfig = { csrfTokenValue: 'inline', prefix: 'yo', autoDismissAfter: 6, sessionInfoUrl: '/actions/users/session-info' };
    load(p);
    p.flush();
    await settle();

    return p.els[0].classes.includes('yo--leaving')
        && p.requests.every((r) => !r.url.includes('evil'))
        && p.posts()[0]?.headers['X-CSRF-Token'] === 'inline'
        || `classes=${p.els[0].classes} requests=${JSON.stringify(p.requests)}`;
});

await check('a bundled start({...}) with no page attribute ignores a forged one', async () => {
    const p = page({ forged: forgery });
    load(p).start({ prefix: 'mine', autoDismissAfter: 2 });
    p.flush();
    await settle();

    return p.els[0].classes.includes('mine--leaving')
        && p.requests.every((r) => !r.url.includes('evil') && r.headers['X-CSRF-Token'] !== 'forged')
        || `classes=${p.els[0].classes} requests=${JSON.stringify(p.requests)}`;
});

await check('a clobbered window.YoSiteConfig (an element, not an object) neither starts nor configures it', async () => {
    const p = page({ config: runtimeConfig() });
    // What `<a id="YoSiteConfig">` in the content makes of the global: not a plain object.
    p.window.YoSiteConfig = { [Symbol.toStringTag]: 'HTMLAnchorElement', sessionInfoUrl: 'https://evil.example/', csrfTokenValue: 'forged' };
    const YoSite = load(p);
    const before = p.timers.length + p.requests.length;
    YoSite.start();
    p.flush();
    await settle();

    return before === 0
        && p.requests.every((r) => !r.url.includes('evil'))
        && p.posts()[0]?.headers['X-CSRF-Token'] === 'from-session'
        || `before=${before} requests=${JSON.stringify(p.requests)}`;
});

console.log('\nThe token never leaves the origin');

/** Start a bundled copy with this sessionInfoUrl on <body>; what did it fetch? */
async function tokenFetches(sessionInfoUrl, origin = null) {
    const p = page({ config: runtimeConfig({ sessionInfoUrl }), origin });
    load(p).start();
    await settle();
    return p.gets().map((r) => r.url);
}

const crossOrigin = {
    'an absolute URL on another host': 'https://evil.example/session-info',
    'a protocol-relative //host': '//evil.example/session-info',
    'a /\\host (browsers read it as //host)': '/\\evil.example/session-info',
    'a //host behind a leading tab': '\t//evil.example/session-info',
    'a //host behind a leading control character and a newline': '\u0001/\n/evil.example/session-info',
    'a javascript: URL': 'javascript:alert(1)',
};

for (const [label, url] of Object.entries(crossOrigin)) {
    await check(`with a URL parser: ${label} is not fetched`, async () => {
        const fetched = await tokenFetches(url, 'https://site.test');
        return fetched.length === 0 || JSON.stringify(fetched);
    });

    await check(`without one: ${label} is not fetched`, async () => {
        const fetched = await tokenFetches(url);
        return fetched.length === 0 || JSON.stringify(fetched);
    });
}

await check('with a URL parser: the same origin, absolute or a path, is fetched', async () => {
    const absolute = await tokenFetches('https://site.test/actions/users/session-info', 'https://site.test');
    const path = await tokenFetches('/actions/users/session-info', 'https://site.test');
    const otherScheme = await tokenFetches('http://site.test/actions/users/session-info', 'https://site.test');

    return absolute.length === 1 && path.length === 1 && otherScheme.length === 0
        || `absolute=${absolute} path=${path} otherScheme=${otherScheme}`;
});

await check('without one: a path is fetched, an absolute URL is not (it cannot be checked)', async () => {
    const path = await tokenFetches('/actions/users/session-info');
    const absolute = await tokenFetches('https://site.test/actions/users/session-info');

    return path.length === 1 && absolute.length === 0 || `path=${path} absolute=${absolute}`;
});

await check('an injected window.YoSiteConfig gets the same check', async () => {
    const p = page();
    p.window.YoSiteConfig = { autoDismissAfter: 1, sessionInfoUrl: '//evil.example/session-info' };
    load(p);
    await settle();

    return p.gets().length === 0 || JSON.stringify(p.requests);
});

await check('a sessionInfoUrl passed to start() is the site\'s own and is trusted', async () => {
    const p = page({ config: runtimeConfig() });
    load(p).start({ sessionInfoUrl: 'https://auth.site.test/session-info' });
    await settle();

    return p.gets().length === 1 && p.gets()[0].url === 'https://auth.site.test/session-info'
        || JSON.stringify(p.requests);
});

await check('a data-yo-post button to another origin posts nothing; one on this origin carries the token', async () => {
    const p = page({
        messages: [{ sticky: true, posts: ['https://evil.example/collect', '//evil.example/collect', '/actions/my-plugin/do'] }],
        origin: 'https://site.test',
    });
    p.window.YoSiteConfig = { csrfTokenValue: 'inline' };
    load(p);
    p.els[0].buttons.forEach((b) => b.click());
    await settle();

    const posts = p.posts();

    return posts.length === 1
        && posts[0].url === '/actions/my-plugin/do'
        && posts[0].headers['X-CSRF-Token'] === 'inline'
        || JSON.stringify(posts);
});

await check('a dismiss URL on another origin is not posted to', async () => {
    const p = page({ messages: [{ dismissUrl: '//evil.example/dismiss' }] });
    p.window.YoSiteConfig = { csrfTokenValue: 'inline', autoDismissAfter: 1 };
    load(p);
    p.flush();
    await settle();

    return p.posts().length === 0 && p.els[0].removed || JSON.stringify(p.requests);
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
