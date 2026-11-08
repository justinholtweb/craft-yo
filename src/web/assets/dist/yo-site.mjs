/**
 * Yo's front-end script, as an ES module, for a site that bundles its own JavaScript.
 *
 *     import YoSite from './vendor/yo/yo-site.mjs';
 *     YoSite.start();   // reads the page's {{ craft.yo.runtimeConfig() }}
 *
 * Copy this file and yo-site.js side by side. This is a wrapper rather than a second copy of the
 * script: yo-site.js still sets `window.YoSite` and `window.YoSiteConfig` (the message templates'
 * click handlers read the CSRF token from the latter), and a page that also has the injected copy
 * ends up with one instance, not two.
 *
 * It deliberately does not import DataStar. The site supplies that — Yo's own `datastar.js` if it
 * has none, or its existing DataStar 1.0 — because two copies on one page both answer every
 * `datastar-fetch` event and patch each other's responses.
 */
import './yo-site.js';

export default window.YoSite;
