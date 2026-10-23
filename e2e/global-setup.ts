import { execFileSync } from 'node:child_process';

/** Rebuilds the antre_test database so every run starts from the same two made-up businesses. */
export default function globalSetup() {
    const env = {
        ...process.env,
        DB_CONNECTION: 'pgsql',
        DB_HOST: process.env.E2E_DB_HOST ?? '127.0.0.1',
        DB_PORT: process.env.E2E_DB_PORT ?? '5433',
        DB_DATABASE: process.env.E2E_DB_DATABASE ?? 'antre_test',
        DB_USERNAME: process.env.E2E_DB_USERNAME ?? 'postgres',
        DB_PASSWORD: process.env.E2E_DB_PASSWORD ?? 'postgres',
        DB_URL: '',
    };

    const artisan = (...args: string[]) => execFileSync('php', ['artisan', ...args], { env, stdio: 'inherit' });

    artisan('migrate:fresh', '--seed', '--force', '--quiet');

    // Screenshots need a day with history behind the dashboard numbers.
    if (process.env.SCREENSHOTS) {
        artisan('db:seed', '--class=DemoDaySeeder', '--force', '--quiet');
    }
}
