// Shared helpers for the k6 scripts: the target guard, the manifest and a small browser-like session.
import http from 'k6/http';
import { check } from 'k6';

export const BASE = (__ENV.BASE_URL || 'http://127.0.0.1:8010').replace(/\/$/, '');

// Load must never reach the public demo. Only a local address is accepted, for the app and
// for the websocket, and a Vercel or portfolio host is always refused.
export function guardHost(url) {
    const host = url.replace(/^[a-z]+:\/\//i, '').split(/[/:]/)[0];
    if (/(^|\.)(vercel\.app|vercel\.com|vercel\.sh|is-a\.dev)$/i.test(host) || /antre-eight/i.test(host)) {
        throw new Error(`Refusing to load test ${url}: that is production.`);
    }
    if (!['localhost', '127.0.0.1', '[', '::1'].includes(host)) {
        throw new Error(`Refusing to load test ${url}: only localhost is allowed.`);
    }
}

guardHost(BASE);

export const manifest = JSON.parse(open(__ENV.MANIFEST || '../out/manifest.json'));

// Every staff account, in counter order, so neighbours in the list share a counter.
export const staffUsers = manifest.branches.flatMap((branch) =>
    branch.counters.flatMap((counter) =>
        counter.staff.map((email) => ({ email, counterId: counter.id, branchSlug: branch.slug, branchId: branch.id })),
    ),
);

export const pick = (list) => list[Math.floor(Math.random() * list.length)];

const INERTIA = {
    'X-Inertia': 'true',
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'text/html, application/xhtml+xml',
};

/** One cookie jar: a logged-in staff member or an anonymous customer. */
export class Session {
    constructor(email = null) {
        this.email = email;
        this.jar = new http.CookieJar();
        this.version = '';
    }

    token() {
        const cookie = this.jar.cookiesForURL(`${BASE}/`)['XSRF-TOKEN'];

        return cookie ? decodeURIComponent(cookie[0]) : '';
    }

    params(kind, headers = {}, name = null) {
        return {
            jar: this.jar,
            redirects: 0,
            headers,
            tags: { kind, ...(name ? { name } : {}) },
        };
    }

    get(path, kind, headers = {}, name = null) {
        return http.get(`${BASE}${path}`, this.params(kind, headers, name));
    }

    // A form post the way the browser sends it, with the CSRF token from the cookie.
    post(path, body, kind, referer, name = null) {
        return http.post(
            `${BASE}${path}`,
            body,
            this.params(kind, { 'X-XSRF-TOKEN': this.token(), Referer: `${BASE}${referer}` }, name),
        );
    }

    // The same request as post(), for http.batch.
    postRequest(path, body, kind, referer, name = null) {
        return ['POST', `${BASE}${path}`, body, this.params(kind, { 'X-XSRF-TOKEN': this.token(), Referer: `${BASE}${referer}` }, name)];
    }

    login() {
        this.get('/login', 'login_page');
        const r = this.post('/login', { email: this.email, password: manifest.password }, 'login', '/login');
        const ok = check(r, { 'login accepted': (res) => res.status === 302 && !String(res.headers.Location).includes('/login') });

        return ok;
    }

    /** The counter page as Inertia JSON: { current, waiting_count, ... } or null. */
    counterState(counterId) {
        const path = `/console/counters/${counterId}`;
        if (this.version === '') {
            // The first page load is plain HTML and tells us the asset version Inertia wants back.
            const html = this.get(path, 'counter_page_html', {}, 'GET /console/counters/:id (html)');
            const m = String(html.body).match(/(?:&quot;|")version(?:&quot;|"):\s*(?:&quot;|")([a-f0-9]+)(?:&quot;|")/);
            this.version = m ? m[1] : '-';
        }

        const headers = { ...INERTIA, 'X-Inertia-Version': this.version === '-' ? '' : this.version };
        const r = this.get(path, 'counter_page', headers, 'GET /console/counters/:id');
        const ok = check(r, { 'counter page ok': (res) => res.status === 200 });

        return ok ? r.json('props') : null;
    }
}

/**
 * A 302 or 303 that does not bounce to the login page. The app also redirects back when it
 * refuses an action, so this does not prove the action worked; the checker does.
 */
export const notBounced = (r) => (r.status === 302 || r.status === 303) && !String(r.headers.Location).includes('/login');
