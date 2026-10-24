# antre

[![ci](https://github.com/ramaraihan12/antre/actions/workflows/ci.yml/badge.svg)](https://github.com/ramaraihan12/antre/actions/workflows/ci.yml)

Queue (antrean) and booking system for small clinics and barbershops. Customers take a walk-in ticket from their phone or book a slot online with a deposit. Staff call numbers from a console, and a TV in the waiting room shows and announces them in Indonesian. Everything updates live over websockets.

Every business, branch, person and phone number in the demo data is made up: a dental clinic called Klinik Gigi Senyum (two branches in Salatiga) and a barbershop called Kumis Tipis. It runs on your laptop with Postgres and costs nothing.

![Staff console for Loket 1 showing the ticket being served, the next tickets and the booking check-in box](docs/console.png)

![TV display with the current number per counter and the last five calls](docs/display.png)

The first shot is the staff console, the second is the TV display for the same branch. The other two screenshots are [the booking page](docs/booking.png) and [the owner dashboard](docs/dashboard.png).

The part I care most about is that two counters never get the same ticket. `antre:simulate` starts one PHP process per counter against Postgres 17 on my laptop (Ryzen 9 5900HX, 16 GB) and checks the result afterwards:

```console
$ php artisan antre:simulate --counters=4 --tickets=400
database: pgsql (PostgreSQL 17.11 (Debian 17.11-1.pgdg12+2) on x86_64-pc-linux-gnu) (trimmed)

tickets waiting at start   400
calls made                 400
tickets done               400
still waiting              0
called more than once      0 (workers saw 0, database has 0)
calls per counter          100 / 99 / 100 / 101
wall time                  7.85 s (51 calls/s)
call-next latency          p50 43.5 ms   p95 63.7 ms   p99 88.7 ms   max 90.2 ms
result: every ticket was called exactly once
```

That is a real run, and the raw logs of it and of an 8 counter, 800 ticket run (p50 48.6 ms, p95 73.6 ms, nothing called twice) are in my notes outside the repo. The workers run with broadcasting off, so the latency is the database work of one call.

## How it works

- **Tenants.** A business has branches, a branch has services (a code letter, a name, an average duration) and counters. Every tenant model uses a global scope keyed on the logged-in user's business, and policies check it again. Public pages find rows by branch slug or an unguessable `public_id`.
- **Call next.** In one transaction the counter row is locked, then the next waiting ticket for a service that counter serves is picked with `SELECT ... FOR UPDATE SKIP LOCKED`. Paid bookings that checked in come first (priority), then ticket order. Recall, skip and done lock the ticket row and check its state before changing it.
- **Live updates.** Calls, skips and done events are broadcast through Laravel Reverb to the display, the staff console and the ticket page. There is no polling. A dead socket is caught and logged so it cannot fail a call. Staff channels are authorized per business.
- **Estimated wait.** Position counts waiting tickets of the same service ahead of yours. The wait uses the average of the last 20 finished tickets for that service (call to done), and falls back to the service's configured duration until there is history.
- **Voice.** The TV page builds the sentence ("Nomor antrean A dua belas, silakan ke loket dua") and speaks it with the browser Web Speech API, `id-ID` voice, after a chime. Browsers need one click to allow sound, so the page shows a "Suara aktif" switch.
- **Booking and deposit.** Slots come from branch opening hours and the service duration, and capacity is the number of counters serving that service. A booking is `pending_payment` for 15 minutes, then `antre:expire-bookings` (scheduled every minute) expires it. Payment goes through Midtrans Snap (sandbox).
- **Webhook.** `POST /webhooks/midtrans` checks `sha512(order_id + status_code + gross_amount + server key)` with `hash_equals`, writes the notification to a log table with a unique key (duplicates are ignored), and moves the booking through `pending_payment -> paid -> checked_in`, or `expired` and `cancelled`. Out-of-order and repeated notifications cannot move a booking backwards.
- **Fake payments.** Without Midtrans keys a local driver signs a notification itself and sends it through the same webhook code. It is labelled local only and is refused when `APP_ENV=production`.

## Stack

PHP 8.4, Laravel 12.70, Inertia 2.0 with React 19, TypeScript 5.7 and Tailwind 4.0 (the official React starter kit), Laravel Reverb 1.12, Postgres 17, database queue driver and the Laravel scheduler, Pest 4 with Larastan 3.13 and Pint 1.32, Playwright for the browser tests, ESLint and Prettier.

## Run it

You need PHP 8.4 with `pdo_pgsql`, Composer, Node 24 and a Postgres 17 you can create a database in. The defaults in `.env.example` expect `postgres:postgres` on `localhost:5433` and a database called `antre`.

```bash
cp .env.example .env
composer install && npm ci
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8010    # app
php artisan reverb:start         # websockets on 8090
php artisan queue:work           # database queue
php artisan schedule:work        # expires unpaid bookings
npm run dev                      # vite on 5180
```

Open `http://localhost:8010`. Walk-in page: `/q/senyum-salatiga-kota`, TV: `/display/senyum-salatiga-kota`, booking: `/book/senyum-salatiga-kota`. Staff log in at `/login` with `dimas@senyum.test` and the owner with `owner@senyum.test`. Every demo account uses the password `password`. For a dashboard with history, run `php artisan db:seed --class=DemoDaySeeder`.

Or everything in Docker (app, Reverb, queue worker, scheduler and Postgres, all ports on `127.0.0.1`):

```bash
cp .env.example .env
docker compose up -d
docker compose exec app php artisan db:seed --force
```

| Variable | Default | |
| --- | --- | --- |
| `PAYMENT_DRIVER` | empty | `midtrans`, `fake`, or empty (midtrans if keys are set, else fake) |
| `MIDTRANS_SERVER_KEY` | empty | sandbox server key, used for Snap and for checking webhook signatures |
| `MIDTRANS_CLIENT_KEY` | empty | sandbox client key for the Snap popup |
| `BOOKING_HOLD_MINUTES` | 15 | how long an unpaid booking keeps its slot |
| `REVERB_*` | local values | keys and ports for the websocket server |

For the Midtrans webhook to reach a laptop you need a tunnel to `/webhooks/midtrans`. I have not done that, see Notes.

## Tests

```bash
export TEST_DATABASE_URL=postgresql://postgres:postgres@localhost:5433/antre_test
vendor/bin/pest
npm run build && npx playwright test   # browser tests, wipes antre_test
```

The Pest suite covers the queue state machine, slot capacity, booking expiry and check-in, the webhook (bad signature, duplicates, out-of-order, late payments), tenant isolation through route model binding, policies and broadcast channel authorization, and the dashboard numbers. The concurrency tests need Postgres and are skipped without `TEST_DATABASE_URL`. Midtrans is never called: the Snap client is faked, and webhook tests sign their own payloads. My last run was 172 tests, 617 assertions, in 26 seconds. The two Playwright tests start a real app server and a real Reverb server: staff clicks Call next and the display and the ticket page show the number without a reload, and a customer books a slot and pays with the fake driver.

CI runs Pint, Larastan, ESLint, Prettier and `tsc` in one job, Pest against a Postgres 17 service in a second, and the Vite build in a third. The browser tests do not run in CI.

## Notes

- I have only run the Midtrans Snap and webhook paths against the fake driver and mocks. The real Snap call and the signature format are written against the docs and not tried with sandbox keys.
- Browser speech depends on the machine: the `id-ID` voice has to be installed, and Chrome only speaks after a click on the page. I tested the sentence builder, and the browser tests check that the number shows up, not that the voice is audible.
- Reverb runs as one process. If it is down, calls still succeed (a failed broadcast is logged and ignored), but screens that missed an event only catch up on their next reload.
- Accounts are created by seeding or by hand. There is no self-service sign up and no invite flow for staff.
- The latency numbers are four processes on a laptop with the database in a local container, so they say little about a real clinic server. A clinic never has 100 calls a second anyway.
- No refunds, no WhatsApp or SMS reminders, no native apps and no language switch. The UI is Bahasa Indonesia only.
- Next: a no-show timer that skips a ticket on its own, and a staff invite flow.

## License

MIT
