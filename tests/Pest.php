<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Counter;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Queue\TicketIssuer;
use Illuminate\Broadcasting\BroadcastManager;
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

/**
 * One business with a branch, two services and two counters.
 * Counter 1 serves A and B, counter 2 serves A only.
 *
 * @param  array<string, mixed>  $branch
 * @return object{business: Business, branch: Branch, a: Service, b: Service, c1: Counter, c2: Counter}
 */
function scenario(array $branch = []): object
{
    $business = Business::factory()->create();
    $branch = Branch::factory()->for($business)->create($branch);
    $a = Service::factory()->for($branch)->create(['name' => 'Cabut gigi', 'code' => 'A', 'deposit_amount' => 25000]);
    $b = Service::factory()->for($branch)->create(['name' => 'Konsultasi', 'code' => 'B']);
    $c1 = Counter::factory()->for($branch)->create(['number' => 1]);
    $c2 = Counter::factory()->for($branch)->create(['number' => 2]);
    $c1->services()->attach([$a->id, $b->id]);
    $c2->services()->attach([$a->id]);

    return (object) compact('business', 'branch', 'a', 'b', 'c1', 'c2');
}

function issue(Branch $branch, Service $service, bool $priority = false): Ticket
{
    return app(TicketIssuer::class)->issue($branch, $service, priority: $priority);
}

function staffOf(Business $business, Role $role = Role::Staff): User
{
    return User::factory()->create(['business_id' => $business->id, 'role' => $role]);
}

/**
 * Points broadcasting at Reverb with throwaway keys and registers the channel callbacks on it,
 * so /broadcasting/auth can be exercised. Nothing connects to a server, only signatures are made.
 */
function useReverbBroadcaster(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8090,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    app(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');
}
