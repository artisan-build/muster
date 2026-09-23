<?php

declare(strict_types=1);

use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

function signInToMuster(): User
{
    $user = User::query()->create([
        'name' => 'Test Created Operator',
        'email' => 'operator-'.bin2hex(random_bytes(4)).'@example.test',
        'password' => 'test-password',
    ]);
    $user->refresh();

    test()->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);

    return $user;
}

it('refuses unauthenticated monitor management requests through package auth', function (): void {
    $monitor = Monitor::query()->create([
        'name' => 'Test Created Protected Monitor',
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
    ]);

    $requests = [
        $this->getJson(route('monitors.index')),
        $this->getJson(route('monitors.create')),
        $this->postJson(route('monitors.store'), []),
        $this->getJson(route('monitors.show', $monitor)),
        $this->getJson(route('monitors.edit', $monitor)),
        $this->putJson(route('monitors.update', $monitor), []),
        $this->patchJson(route('monitors.disable', $monitor)),
        $this->patchJson(route('monitors.enable', $monitor)),
    ];

    foreach ($requests as $response) {
        $response->assertUnauthorized();
    }
});

it('refuses unauthenticated bound routes before revealing monitor existence', function (string $route): void {
    config(['app.debug' => false]);

    $monitor = Monitor::query()->create([
        'name' => 'Test Created Existing Monitor',
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
    ]);

    $existing = $this->getJson(route($route, $monitor));
    $missing = $this->getJson(route($route, $monitor->id + 1));

    $existing->assertUnauthorized();
    $missing->assertUnauthorized();

    expect($missing->getContent())->toBe($existing->getContent());
})->with([
    'show' => 'monitors.show',
    'edit' => 'monitors.edit',
]);

it('rejects invalid monitor route ids before querying monitors', function (string $monitorId): void {
    signInToMuster();
    $monitorQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$monitorQueries): void {
        if (str_contains($query->sql, 'monitors')) {
            $monitorQueries[] = $query->sql;
        }
    });

    $requests = [
        fn () => $this->get(route('monitors.show', $monitorId)),
        fn () => $this->get(route('monitors.edit', $monitorId)),
        fn () => $this->put(route('monitors.update', $monitorId)),
        fn () => $this->patch(route('monitors.disable', $monitorId)),
        fn () => $this->patch(route('monitors.enable', $monitorId)),
    ];

    foreach ($requests as $request) {
        $request()->assertNotFound();
    }

    expect($monitorQueries)->toBe([]);
})->with([
    'non-numeric' => 'not-a-monitor',
    'decimal' => '1.5',
    'bigint overflow' => '99999999999999999999',
]);

it('creates, displays, edits, disables, and enables a monitor', function (): void {
    signInToMuster();

    $name = 'Test Created Billing Export';
    $editedName = 'Test Created Nightly Billing Export';

    $this->get(route('monitors.create'))
        ->assertOk()
        ->assertSee('data-testid="monitor-create-heading"', false)
        ->assertSee('data-testid="monitor-create-form"', false);

    $this->post(route('monitors.store'), [
        'name' => $name,
        'cadence_seconds' => 600,
        'grace_seconds' => 90,
    ])->assertRedirect();

    $monitor = Monitor::query()->sole();

    expect($monitor->name)->toBe($name)
        ->and($monitor->cadence_seconds)->toBe(600)
        ->and($monitor->grace_seconds)->toBe(90)
        ->and($monitor->is_enabled)->toBeTrue()
        ->and($monitor->status)->toBe('pending')
        ->and($monitor->last_check_in_at)->toBeNull()
        ->and($monitor->next_due_at)->toBeNull();

    $this->get(route('monitors.index'))
        ->assertOk()
        ->assertSee('data-testid="monitors-heading"', false)
        ->assertSee('data-testid="monitors-list"', false)
        ->assertSee($name);

    $this->get(route('monitors.show', $monitor))
        ->assertOk()
        ->assertSee('data-testid="monitor-detail-heading"', false)
        ->assertSee('data-testid="monitor-detail-summary"', false)
        ->assertSee($name)
        ->assertSee('600 seconds')
        ->assertSee('90 seconds');

    $this->get(route('monitors.edit', $monitor))
        ->assertOk()
        ->assertSee('data-testid="monitor-edit-heading"', false)
        ->assertSee('data-testid="monitor-edit-form"', false)
        ->assertSee($name);

    $this->put(route('monitors.update', $monitor), [
        'name' => $editedName,
        'cadence_seconds' => 900,
        'grace_seconds' => 120,
    ])->assertRedirect(route('monitors.show', $monitor));

    $monitor->refresh();

    expect($monitor->name)->toBe($editedName)
        ->and($monitor->cadence_seconds)->toBe(900)
        ->and($monitor->grace_seconds)->toBe(120);

    $this->patch(route('monitors.disable', $monitor))
        ->assertRedirect(route('monitors.show', $monitor));
    expect($monitor->refresh()->is_enabled)->toBeFalse();

    $this->patch(route('monitors.enable', $monitor))
        ->assertRedirect(route('monitors.show', $monitor));
    expect($monitor->refresh()->is_enabled)->toBeTrue();
});

it('rejects invalid monitor timing without persisting a monitor', function (array $timing, string $error): void {
    signInToMuster();

    $this->post(route('monitors.store'), [
        'name' => 'Test Created Invalid Timing',
        ...$timing,
    ])->assertSessionHasErrors($error);

    expect(Monitor::query()->count())->toBe(0);
})->with([
    'cadence must be positive' => [['cadence_seconds' => 0, 'grace_seconds' => 30], 'cadence_seconds'],
    'grace must be nonnegative' => [['cadence_seconds' => 60, 'grace_seconds' => -1], 'grace_seconds'],
]);

it('presents enabled monitor states with semantic status badges', function (): void {
    signInToMuster();

    foreach (['pending', 'healthy', 'running', 'late', 'missed', 'failed'] as $status) {
        $monitor = Monitor::query()->create([
            'name' => 'Test Created '.ucfirst($status).' Monitor',
            'cadence_seconds' => 300,
            'grace_seconds' => 30,
        ]);
        $monitor->forceFill(['status' => $status])->save();
    }

    $disabled = Monitor::query()->create([
        'name' => 'Test Created Disabled Monitor',
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
    ]);
    $disabled->forceFill(['is_enabled' => false])->save();

    $index = $this->get(route('monitors.index'))->assertOk();

    foreach (['pending', 'healthy', 'running', 'late', 'missed', 'failed'] as $status) {
        $index->assertSee('class="status status-'.$status.'" data-testid="monitor-status"', false)
            ->assertSee(ucfirst($status));
    }

    $index->assertSee('class="status status-disabled" data-testid="monitor-status"', false)
        ->assertSee('Disabled');

    $failed = Monitor::query()->where('status', 'failed')->sole();
    $this->get(route('monitors.show', $failed))
        ->assertOk()
        ->assertSee('class="status status-failed" data-testid="monitor-status"', false)
        ->assertSee('Failed');
});
