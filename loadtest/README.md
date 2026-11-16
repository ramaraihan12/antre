# Load test

A k6 test that drives the whole app over HTTP and websockets, and a checker that proves afterwards that no ticket was called twice. `antre:simulate` calls `QueueDesk` directly with broadcasting off, so it says nothing about login, sessions, Apache, the Reverb broadcast after each commit or the TV display. This does.

Run it only against a copy you started yourself. The scripts refuse any address that is not `localhost`, `127.0.0.1` or `[::1]`, any address with `user@` in it, and any URL that contains `vercel`, `is-a.dev` or `antre-eight` anywhere, so the public demo cannot be hit by accident. The same goes for `WS_URL`.

## What runs

- **staff**: logged-in users, two per counter, press call next (15% of presses sent twice at once, a double click), then recall, skip or done.
- **walkins**: customers open the join page, take a ticket, open the ticket page and sometimes cancel.
- **checkins**: every paid booking is checked in once, some submitted twice.
- **tv**: websocket clients on `display.<branch id>` through Reverb's Pusher protocol. Every snapshot they receive is written to a log.
- **drain**: after the load stops, staff keep calling until nothing waits, so every ticket can be accounted for.

`php artisan antre:loadtest-seed` creates the data: branches, services, counters, two staff per counter, waiting tickets and paid bookings for today. It writes `loadtest/out/manifest.json` with a new random password for the staff each run. It refuses to run when `APP_ENV=production` and replaces the data of an earlier run (the business with slug `loadtest`).

`php artisan antre:loadtest-check` reads the database, not what k6 says, and prints PASS or FAIL for each of these: a ticket that reached a counter has exactly one call, no ticket was called more than once or on two counters, the counter on a ticket is the one that called it, no counter holds two called tickets, ticket numbers are unique, every checked-in booking has one ticket, and nothing is left waiting or called. Add `--allow-open` for a run you cut short, and `--json=path` for a file. The exit code is 1 on any FAIL.

## The walk-in switch

Walk-ins are limited to 30 a minute per address and k6 sends everything from one address. `LOADTEST_LIFT_WALKIN_LIMIT=true` lifts that limit and no other. It is ignored when `APP_ENV=production` or `DEMO_MODE` is on (`tests/Feature/WalkInThrottleTest.php`), and the workflow runs with `APP_ENV=local`.

## In GitHub Actions

Actions tab, `load-test`, Run workflow. Nothing is scheduled. The inputs set the size: staff (empty means two per counter), walk-ins a second, TV clients, ramp and hold seconds, and how many branches, counters and waiting tickets to seed. The defaults are a small run of a few minutes plus the image build.

The job builds the repo's own `Dockerfile` (Apache with mod_php, like the demo), starts Postgres 17 with `pg_stat_statements` and Reverb next to it, runs k6, then the checker. The run fails if a k6 threshold or the checker fails, or if any earlier step (app, Reverb, k6) did not run or failed: a checker that did not get a run to check is a failure, not a pass, and the artifact `load-test` is uploaded either way:

| File | What |
| --- | --- |
| `k6-summary.json` | k6 summary, with latency per kind of request and the `tv_*` metrics |
| `checker.txt`, `checker.json` | the checker output |
| `pg-stat-statements-top.txt` | the 25 statements that took the most total time |
| `pg-connections.csv` | Postgres connections, active ones and lock waits every 2 seconds |
| `tv-snapshots.log` | one line per snapshot a TV client received |
| `app.log`, `reverb.log` | stderr of the app (warnings) and Reverb |

The root `Dockerfile` has no opcache, unlike `Dockerfile.vercel`, so PHP is slower here than on the demo. Compare runs with each other, not with the demo.

## Locally

You need PHP 8.4 with `pdo_pgsql`, a throwaway Postgres 17 database, a built front end (`npm run build`) and [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/). In `.env` set `DB_*` to that database, `APP_ENV=local` and `LOADTEST_LIFT_WALKIN_LIMIT=true`, then:

```bash
php artisan migrate
php artisan antre:loadtest-seed
php artisan serve --port=8010        # one process: fine for trying the scripts, not for numbers
php artisan reverb:start --port=8090

k6 run --log-format=raw \
  --console-output=loadtest/out/tv-snapshots.log \
  --summary-export=loadtest/out/k6-summary.json \
  loadtest/k6/load.js

php artisan antre:loadtest-check
```

For numbers that mean something, run the app with `docker compose up` (Apache, as in the workflow) and put the two settings above in `.env` first. Pass `BASE_URL` (default `http://127.0.0.1:8010`), `WS_URL` (default `ws://127.0.0.1:8090`) and `REVERB_APP_KEY` (default `antre-local-key`) to k6 with `-e` if yours differ.

Sizes are environment variables: `STAFF_VUS`, `WALKIN_RATE`, `TV_CLIENTS`, `CHECKIN_VUS`, `RAMP_S`, `DURATION_S` and `DRAIN_S`. `STAFF_VUS` defaults to every seeded account, branches x counters x 2, which is two per counter. Staff take the accounts in order, one VU per account, so an odd value is rounded down to an even one and a value above the number of accounts is capped, both with a warning (k6 writes it to `tv-snapshots.log`, not the job log). An empty or 0 value means the default. A quick try: `RAMP_S=5 DURATION_S=15 STAFF_VUS=6 WALKIN_RATE=2 TV_CLIENTS=4 k6 run ...`.

## The snapshot log

Each line is `SNAPSHOT {...}` with `t` (receive time, ms), `vu`, `branch`, `version` (the branch's display counter), `displayed` (whether the TV's accept rule, strictly newer version wins, would have put it on screen), `counters` (the code on each counter, `null` when free), `recent` (the last five calls) and `announcement`. At the end of the run each client adds a `FINAL {...}` line: the version it ended on, the database's version and whether the two screens show the same content.

The k6 TV client replays what it receives through the TV's real accept rule (`loadtest/k6/viewer.js`, unit tests next to it) and counts what a viewer would see: `tv_stale_displayed` (a displayed snapshot whose content was already shown earlier and replaced), `tv_same_version_different_content` (two arrivals with one version and different content), and `tv_final_mismatch` out of `tv_final_checked` (clients whose last screen differs from the database once the run is over). `tv_raw_version_regressions` counts arrival order before the accept rule and is only informational: the version is a per-branch counter taken from the database inside each queue change, so a lower version arriving later is reordered delivery, which the display ignores. Every snapshot is built inside the transaction of its change, right after the version is raised and while the branch row is locked, and that exact payload is broadcast after commit, so a version and its content always come from one moment. Delivery time uses the snapshot's `built_at` in ms. `python loadtest/tv_order.py DIR` runs the same replay over downloaded artifacts and compares final screens with `checker.json`.

A run that crosses midnight UTC is not valid: the branches use UTC dates, so a ticket's day would change halfway.
