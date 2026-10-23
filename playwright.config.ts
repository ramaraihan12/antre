import { defineConfig } from '@playwright/test';

/*
 * Browser tests run against a real app server and a real Reverb server, both started here.
 * They use the antre_test database and wipe it (migrate:fresh --seed) before the run.
 * Build the assets first: npm run build
 */
const APP_PORT = process.env.APP_PORT ?? '8010';
const REVERB_PORT = process.env.REVERB_PORT ?? '8090';

export const baseURL = `http://127.0.0.1:${APP_PORT}`;

const env = {
    ...(process.env as Record<string, string>),
    DB_CONNECTION: 'pgsql',
    DB_HOST: process.env.E2E_DB_HOST ?? '127.0.0.1',
    DB_PORT: process.env.E2E_DB_PORT ?? '5433',
    DB_DATABASE: process.env.E2E_DB_DATABASE ?? 'antre_test',
    DB_USERNAME: process.env.E2E_DB_USERNAME ?? 'postgres',
    DB_PASSWORD: process.env.E2E_DB_PASSWORD ?? 'postgres',
    DB_URL: '',
    APP_ENV: 'local',
    APP_URL: baseURL,
    BROADCAST_CONNECTION: 'reverb',
    PAYMENT_DRIVER: 'fake',
    REVERB_SERVER_HOST: '127.0.0.1',
    REVERB_SERVER_PORT: REVERB_PORT,
    REVERB_HOST: '127.0.0.1',
    REVERB_PORT: REVERB_PORT,
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
};

export default defineConfig({
    testDir: './e2e',
    globalSetup: './e2e/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    timeout: 60_000,
    expect: { timeout: 10_000 },
    use: {
        baseURL,
        trace: 'retain-on-failure',
        viewport: { width: 1280, height: 800 },
        locale: 'id-ID',
    },
    webServer: [
        {
            command: `php artisan serve --host=127.0.0.1 --port=${APP_PORT} --no-reload`,
            url: `${baseURL}/up`,
            env,
            reuseExistingServer: false,
            timeout: 60_000,
        },
        {
            command: `php artisan reverb:start --host=127.0.0.1 --port=${REVERB_PORT}`,
            port: Number(REVERB_PORT),
            env,
            reuseExistingServer: false,
            timeout: 60_000,
        },
    ],
});
