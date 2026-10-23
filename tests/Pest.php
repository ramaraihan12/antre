<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Tests run on in-memory SQLite by default. When TEST_DATABASE_URL is set they run
 * on that Postgres database instead, and the concurrency suite is enabled.
 */
if ($url = getenv('TEST_DATABASE_URL')) {
    foreach (['DB_CONNECTION' => 'pgsql', 'DB_URL' => $url] as $key => $value) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// These tests commit real rows because they start other PHP processes against the database.
pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->in('Concurrency');

function pgOnly(): bool
{
    return (bool) getenv('TEST_DATABASE_URL');
}
