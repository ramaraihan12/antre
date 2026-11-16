// Antre under load. Logged-in staff work two to a counter, customers walk in, cancel and check in,
// and TV clients listen to the display channel on Reverb. Afterwards run
// `php artisan antre:loadtest-check` to prove the queue stayed consistent.
//
// Seed first: php artisan antre:loadtest-seed. Settings come from environment variables, see
// loadtest/README.md.
import http from 'k6/http';
import ws from 'k6/ws';
import exec from 'k6/execution';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { Session, guardHost, notBounced, manifest, pick, staffUsers } from './lib.js';
import { contentOf, createViewer } from './viewer.js';

const num = (name, fallback) => Number(__ENV[name] || fallback);

const RAMP_S = num('RAMP_S', 20);
const DURATION_S = num('DURATION_S', 60);
const DRAIN_S = num('DRAIN_S', 90);
// Two staff per counter: by default every seeded account gets a VU. A given number is rounded
// down to an even one, and capped at the number of accounts, so no counter has a lone user.
// Empty, 0 and non-numbers count as not given.
const requestedStaff = Number(__ENV.STAFF_VUS) || staffUsers.length;
const STAFF_VUS = Math.min(Math.max(2, requestedStaff - (requestedStaff % 2)), staffUsers.length);
if (STAFF_VUS !== requestedStaff) {
    console.warn(`STAFF_VUS ${requestedStaff} became ${STAFF_VUS}: two per counter, at most ${staffUsers.length} accounts.`);
}
const WALKIN_RATE = num('WALKIN_RATE', 5); // new customers a second at the top of the ramp
const CHECKIN_VUS = num('CHECKIN_VUS', 4);
const TV_CLIENTS = num('TV_CLIENTS', 20);
const DOUBLE_CLICK = num('DOUBLE_CLICK', 0.15); // share of call-next presses sent twice at once
const CANCEL = num('CANCEL', 0.15);
const SKIP = num('SKIP', 0.1);
const RECALL = num('RECALL', 0.1);

const WS_URL = (__ENV.WS_URL || 'ws://127.0.0.1:8090').replace(/\/$/, '');
guardHost(WS_URL);
const REVERB_KEY = __ENV.REVERB_APP_KEY || 'antre-local-key';

const LOAD_S = RAMP_S + DURATION_S;
const DRAIN_START_S = LOAD_S + 15; // staff ramp down for up to 10 s
const TOTAL_S = DRAIN_START_S + DRAIN_S;

const bookings = manifest.branches.flatMap((branch) => branch.bookings.map((reference) => ({ branch, reference })));

export const options = {
    scenarios: {
        staff: {
            // One iteration per VU, so exec.scenario.iterationInTest (0..N-1) is a stable index that
            // gives every VU its own account. Each VU loops inside that iteration until the load
            // window ends and starts late by index * RAMP_S / N, which is the ramp.
            executor: 'per-vu-iterations',
            exec: 'staff',
            vus: STAFF_VUS,
            iterations: 1,
            maxDuration: `${LOAD_S + 10}s`,
        },
        walkins: {
            executor: 'ramping-arrival-rate',
            exec: 'walkin',
            startRate: 1,
            timeUnit: '1s',
            preAllocatedVUs: Math.max(5, WALKIN_RATE * 2),
            maxVUs: Math.max(20, WALKIN_RATE * 6),
            stages: [
                { duration: `${RAMP_S}s`, target: WALKIN_RATE },
                { duration: `${DURATION_S}s`, target: WALKIN_RATE },
            ],
        },
        checkins: {
            executor: 'constant-arrival-rate',
            exec: 'checkin',
            // Every paid booking once over the run, a few of them submitted twice.
            rate: Math.max(1, bookings.length),
            timeUnit: `${LOAD_S}s`,
            duration: `${LOAD_S}s`,
            preAllocatedVUs: CHECKIN_VUS,
            maxVUs: CHECKIN_VUS * 2,
        },
        tv: {
            executor: 'constant-vus',
            exec: 'tv',
            vus: TV_CLIENTS,
            duration: `${TOTAL_S}s`,
        },
        drain: {
            // Everybody keeps calling until nothing waits, so the checker can account for every ticket.
            executor: 'per-vu-iterations',
            exec: 'drain',
            vus: staffUsers.length,
            iterations: 1,
            startTime: `${DRAIN_START_S}s`,
            maxDuration: `${DRAIN_S}s`,
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.05'],
        checks: ['rate>0.95'],
        'http_req_duration{kind:call_next}': ['p(95)<2000'],
        'http_req_duration{kind:walkin}': ['p(95)<2000'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

const snapshots = new Counter('tv_snapshots');
const subscribed = new Counter('tv_subscriptions');
const deliveryMs = new Trend('tv_delivery_ms', true); // receive time minus the snapshot's built_at
// What a viewer sees, after the TV's accept rule (strictly newer wins), see viewer.js:
const staleDisplayed = new Counter('tv_stale_displayed'); // displayed snapshots whose content is older than something already displayed
const sameVersion = new Counter('tv_same_version_different_content'); // two arrivals with one version and different content
const finalMismatch = new Counter('tv_final_mismatch'); // clients whose final screen differs from the database at the end
const finalChecked = new Counter('tv_final_checked'); // clients that could be compared at the end
const displayedCount = new Counter('tv_displayed'); // snapshots the TV would have put on screen
const regressions = new Counter('tv_raw_version_regressions'); // informational: arrival order before the accept rule, so reordered delivery counts
const reconnects = new Counter('tv_reconnects');
const staffActions = new Rate('staff_action_not_bounced');

// Per VU: sessions by account, so a VU logs in once.
const sessions = {};

function sessionFor(user) {
    let session = sessions[user.email];
    if (!session) {
        session = sessions[user.email] = new Session(user.email);
        session.loggedIn = session.login();
    }

    return session.loggedIn ? session : null;
}

function press(session, path, kind, counterId) {
    const r = session.post(path, {}, kind, `/console/counters/${counterId}`, `POST ${path.replace(/\d+/g, ':id')}`);
    staffActions.add(notBounced(r));
    check(r, { [`${kind} not bounced to login`]: notBounced });

    return r;
}

function staffRound(session, user) {
    const callPath = `/console/counters/${user.counterId}/call-next`;
    if (Math.random() < DOUBLE_CLICK) {
        // A double click: two presses at the same moment on the same session.
        const request = () => session.postRequest(callPath, {}, 'call_next', `/console/counters/${user.counterId}`, 'POST /console/counters/:id/call-next');
        http.batch([request(), request()]).forEach((r) => staffActions.add(notBounced(r)));
    } else {
        press(session, callPath, 'call_next', user.counterId);
    }

    const state = session.counterState(user.counterId);
    sleep(0.2 + Math.random() * 0.8); // the customer is served

    if (state && state.current) {
        const id = state.current.id;
        if (Math.random() < RECALL) press(session, `/console/tickets/${id}/recall`, 'recall', user.counterId);
        press(session, `/console/tickets/${id}/${Math.random() < SKIP ? 'skip' : 'done'}`, 'finish', user.counterId);
    }
}

export function staff() {
    // Accounts are taken in order and STAFF_VUS is even and at most the number of accounts, so
    // each account has one VU and each counter in use has two.
    const index = exec.scenario.iterationInTest;
    const user = staffUsers[index % staffUsers.length];
    const endsAt = Date.now() + (LOAD_S * 1000 - exec.instance.currentTestRunDuration);

    sleep((index * RAMP_S) / Math.max(1, STAFF_VUS));

    const session = sessionFor(user);
    while (Date.now() < endsAt) {
        if (!session) {
            sleep(5);
            continue;
        }

        staffRound(session, user);
    }
}

let customer = null;

export function walkin() {
    customer = customer || new Session();
    const branch = pick(manifest.branches);
    if (!customer.token()) customer.get(`/q/${branch.slug}`, 'join_page', {}, 'GET /q/:slug');

    const r = customer.post(`/q/${branch.slug}/tickets`, { service_id: String(pick(branch.services)) }, 'walkin', `/q/${branch.slug}`, 'POST /q/:slug/tickets');
    const ticket = String(r.headers.Location || '').match(/\/t\/([a-z0-9]+)$/);
    check(r, { 'walk-in got a ticket': () => ticket !== null });
    if (!ticket) return;

    customer.get(`/t/${ticket[1]}`, 'ticket_page', {}, 'GET /t/:id');

    if (Math.random() < CANCEL) {
        sleep(Math.random());
        const c = customer.post(`/t/${ticket[1]}/cancel`, {}, 'cancel', `/t/${ticket[1]}`, 'POST /t/:id/cancel');
        check(c, { 'cancel not bounced to login': notBounced });
    }
}

export function checkin() {
    const item = bookings[exec.scenario.iterationInTest % bookings.length];
    const user = { email: item.branch.counters[0].staff[0], counterId: item.branch.counters[0].id };
    const session = sessionFor(user);
    if (!session) return;

    const referer = `/console/counters/${user.counterId}`;
    const one = () => session.postRequest('/console/check-in', { reference: item.reference }, 'check_in', referer, 'POST /console/check-in');
    // Some people tap twice. The second one must return the same ticket, not make another.
    const results = Math.random() < 0.3 ? http.batch([one(), one()]) : [http.request(...one())];
    results.forEach((r) => check(r, { 'check-in not bounced to login': notBounced }));
}

export function drain() {
    const user = staffUsers[exec.scenario.iterationInTest % staffUsers.length];
    const session = sessionFor(user);
    if (!session) return;

    const deadline = Date.now() + DRAIN_S * 1000 - 5000;
    let empty = 0;

    while (Date.now() < deadline) {
        const state = session.counterState(user.counterId);
        if (!state) {
            sleep(0.5);
            continue;
        }

        if (state.current) {
            press(session, `/console/tickets/${state.current.id}/done`, 'finish', user.counterId);
        } else if (state.waiting_count === 0) {
            if (++empty >= 2) return;
            sleep(0.5);
        } else {
            empty = 0;
            press(session, `/console/counters/${user.counterId}/call-next`, 'call_next', user.counterId);
        }
    }
}

export function tv() {
    const branch = manifest.branches[exec.vu.idInTest % manifest.branches.length];
    const url = `${WS_URL}/app/${REVERB_KEY}?protocol=7&client=k6&version=1.0.0&flash=false`;
    const left = TOTAL_S * 1000 - exec.instance.currentTestRunDuration;
    if (left < 1000) return;

    const viewer = createViewer();
    let rawSeen = 0;

    const res = ws.connect(url, { tags: { kind: 'tv' } }, (socket) => {
        socket.on('message', (raw) => {
            const now = Date.now();
            const message = JSON.parse(raw);

            if (message.event === 'pusher:connection_established') {
                socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: branch.channel } }));
            } else if (message.event === 'pusher:ping') {
                socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
            } else if (message.event === 'pusher_internal:subscription_succeeded') {
                subscribed.add(1);
            } else if (message.event === 'display.updated') {
                const { snapshot, announcement } = JSON.parse(message.data);
                const result = viewer.receive(snapshot);

                snapshots.add(1);
                deliveryMs.add(now - snapshot.built_at);
                if (result.displayed) displayedCount.add(1);
                if (result.stale) staleDisplayed.add(1);
                if (result.sameVersionDifferentContent) sameVersion.add(1);
                if (viewer.tally().rawRegressions > rawSeen) {
                    rawSeen = viewer.tally().rawRegressions;
                    regressions.add(1);
                }

                // One line per snapshot received. The later stale-display check reads these.
                console.log(`SNAPSHOT ${JSON.stringify({
                    t: now,
                    vu: exec.vu.idInTest,
                    branch: branch.id,
                    version: snapshot.version,
                    displayed: result.displayed,
                    counters: snapshot.counters.map((c) => c.code),
                    recent: snapshot.recent.map((r) => `${r.code}@${r.counter}:${r.kind}`),
                    announcement: announcement ? announcement.id : null,
                })}`);
            }
        });

        socket.setTimeout(() => socket.close(), left);
    });

    // The run is over and staff have stopped: what this TV shows must be what the database holds.
    // Only at the end: a socket that dropped mid-run is compared against a database still moving.
    const shown = viewer.final();
    if (shown !== null && TOTAL_S * 1000 - exec.instance.currentTestRunDuration <= 2000) {
        const truth = new Session().displayState(branch.slug);
        if (truth !== null) {
            finalChecked.add(1);
            const same = contentOf(truth) === shown.content;
            if (!same) finalMismatch.add(1);
            console.log(`FINAL ${JSON.stringify({ vu: exec.vu.idInTest, branch: branch.id, shown_version: shown.version, db_version: truth.version, same })}`);
        }
    }

    check(res, { 'tv websocket opened': (r) => r && r.status === 101 });

    if (TOTAL_S * 1000 - exec.instance.currentTestRunDuration > 2000) {
        reconnects.add(1);
        sleep(1);
    }
}
