<?php

declare(strict_types=1);

use App\Enums\AlertMode;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\DB;

function signInForAlertConfiguration(): User
{
    $user = User::query()->create([
        'name' => 'Test Created Alert Operator',
        'email' => 'alert-operator-'.bin2hex(random_bytes(4)).'@example.test',
        'password' => 'test-password',
    ]);
    $user->refresh();

    test()->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);

    return $user;
}

function createAlertConfigurationMonitor(): Monitor
{
    return Monitor::query()->create([
        'name' => 'Test Created Alert Configuration Monitor',
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
    ]);
}

it('shows independent email and webhook controls only to authenticated operators', function (): void {
    $monitor = createAlertConfigurationMonitor();

    $this->get(route('monitors.edit', $monitor))->assertRedirect('/bfc/login');

    signInForAlertConfiguration();

    $this->get(route('monitors.edit', $monitor))
        ->assertOk()
        ->assertSee('data-testid="monitor-email-alert-configuration"', false)
        ->assertSee('data-testid="monitor-webhook-alert-configuration"', false)
        ->assertSee('data-testid="monitor-email-alert-mode-missed"', false)
        ->assertSee('data-testid="monitor-email-alert-mode-non-zero-exit"', false)
        ->assertSee('data-testid="monitor-webhook-alert-mode-missed"', false)
        ->assertSee('data-testid="monitor-webhook-alert-mode-non-zero-exit"', false)
        ->assertSee('name="email_alert_modes[]"', false)
        ->assertSee('name="webhook_alert_modes[]"', false);
});

it('stores every alert mode combination independently per channel', function (
    string $channel,
    array $selectedModes,
    int $expectedMask,
): void {
    signInForAlertConfiguration();
    $monitor = createAlertConfigurationMonitor();
    $email = 'billing-alerts-'.bin2hex(random_bytes(3)).'@example.test';
    $webhook = 'https://hooks.example.test/muster/'.bin2hex(random_bytes(8)).'?token='.bin2hex(random_bytes(8));
    $modeField = "{$channel}_alert_modes";
    $destinationField = "{$channel}_alert_destination";

    $this->put(route('monitors.update', $monitor), [
        'name' => $monitor->name,
        'cadence_seconds' => $monitor->cadence_seconds,
        'grace_seconds' => $monitor->grace_seconds,
        $modeField => $selectedModes,
        $destinationField => $channel === 'email' ? $email : $webhook,
    ])->assertRedirect(route('monitors.show', $monitor));

    $monitor->refresh();
    $stored = DB::table('monitors')->where('id', $monitor->id)->first();
    $modes = $channel === 'email' ? $monitor->email_alert_modes : $monitor->webhook_alert_modes;
    $destination = $channel === 'email' ? $monitor->email_alert_destination : $monitor->webhook_alert_destination;
    $storedDestination = $stored?->{$destinationField};

    expect($modes->value)->toBe($expectedMask)
        ->and($destination)->toBe($channel === 'email' ? $email : $webhook)
        ->and($stored?->{$modeField})->toBe($expectedMask)
        ->and($storedDestination)->not->toBe($destination);
})->with([
    'email neither' => ['email', [], 0],
    'email missed' => ['email', [(string) AlertMode::Missed->value], AlertMode::Missed->value],
    'email non-zero' => ['email', [(string) AlertMode::NonZeroExit->value], AlertMode::NonZeroExit->value],
    'email both' => ['email', [(string) AlertMode::Missed->value, (string) AlertMode::NonZeroExit->value], 3],
    'webhook neither' => ['webhook', [], 0],
    'webhook missed' => ['webhook', [(string) AlertMode::Missed->value], AlertMode::Missed->value],
    'webhook non-zero' => ['webhook', [(string) AlertMode::NonZeroExit->value], AlertMode::NonZeroExit->value],
    'webhook both' => ['webhook', [(string) AlertMode::Missed->value, (string) AlertMode::NonZeroExit->value], 3],
]);

it('does not validate destination formats for unsubscribed channels', function (): void {
    signInForAlertConfiguration();
    $monitor = createAlertConfigurationMonitor();

    $this->put(route('monitors.update', $monitor), [
        'name' => $monitor->name,
        'cadence_seconds' => $monitor->cadence_seconds,
        'grace_seconds' => $monitor->grace_seconds,
        'email_alert_modes' => [],
        'email_alert_destination' => 'test-created-not-an-email',
        'webhook_alert_modes' => [],
        'webhook_alert_destination' => 'test-created-not-a-url',
    ])->assertRedirect(route('monitors.show', $monitor));

    expect($monitor->refresh()->email_alert_modes->value)->toBe(0)
        ->and($monitor->webhook_alert_modes->value)->toBe(0)
        ->and($monitor->email_alert_destination)->toBe('test-created-not-an-email')
        ->and($monitor->webhook_alert_destination)->toBe('test-created-not-a-url');
});

it('renders persisted mode selections through structural hooks', function (): void {
    signInForAlertConfiguration();
    $monitor = createAlertConfigurationMonitor();
    $monitor->update([
        'email_alert_modes' => AlertMode::Missed->value,
        'webhook_alert_modes' => AlertMode::NonZeroExit->value,
    ]);

    $content = $this->get(route('monitors.edit', $monitor))->assertOk()->getContent();

    expect($content)
        ->toMatch('/data-testid="monitor-email-alert-mode-missed"[^>]*>\s*<input[^>]*checked/s')
        ->toMatch('/data-testid="monitor-webhook-alert-mode-non-zero-exit"[^>]*>\s*<input[^>]*checked/s')
        ->not->toMatch('/data-testid="monitor-email-alert-mode-non-zero-exit"[^>]*>\s*<input[^>]*checked/s')
        ->not->toMatch('/data-testid="monitor-webhook-alert-mode-missed"[^>]*>\s*<input[^>]*checked/s');
});

it('rejects malformed destinations without replacing valid configuration or echoing them in errors', function (): void {
    signInForAlertConfiguration();
    $monitor = createAlertConfigurationMonitor();
    $validEmail = 'valid-alerts@example.test';
    $validWebhook = 'https://hooks.example.test/valid/'.bin2hex(random_bytes(8));

    $monitor->update([
        'email_alert_modes' => AlertMode::Missed->value,
        'email_alert_destination' => $validEmail,
        'webhook_alert_modes' => AlertMode::NonZeroExit->value,
        'webhook_alert_destination' => $validWebhook,
    ]);

    $invalidEmail = 'malformed-email-secret';
    $invalidWebhook = 'ftp://hooks.example.test/'.bin2hex(random_bytes(8)).'?credential=secret-fragment';
    $response = $this->from(route('monitors.edit', $monitor))->put(route('monitors.update', $monitor), [
        'name' => 'Test Created Mutated Name',
        'cadence_seconds' => 900,
        'grace_seconds' => 90,
        'email_alert_modes' => [(string) AlertMode::Missed->value, (string) AlertMode::NonZeroExit->value],
        'email_alert_destination' => $invalidEmail,
        'webhook_alert_modes' => [(string) AlertMode::Missed->value],
        'webhook_alert_destination' => $invalidWebhook,
    ]);

    $response->assertRedirect(route('monitors.edit', $monitor))
        ->assertSessionHasErrors([
            'email_alert_destination' => 'Enter a valid email alert destination.',
            'webhook_alert_destination' => 'Enter a valid HTTP or HTTPS webhook alert destination.',
        ]);

    $errorBag = session('errors')->toArray();
    $this->get(route('monitors.edit', $monitor))
        ->assertOk()
        ->assertDontSee($invalidEmail)
        ->assertDontSee('secret-fragment');

    $monitor->refresh();

    expect(json_encode($errorBag, JSON_THROW_ON_ERROR))
        ->not->toContain($invalidEmail)
        ->not->toContain('secret-fragment')
        ->and($monitor->name)->toBe('Test Created Alert Configuration Monitor')
        ->and($monitor->cadence_seconds)->toBe(300)
        ->and($monitor->email_alert_modes->value)->toBe(AlertMode::Missed->value)
        ->and($monitor->webhook_alert_modes->value)->toBe(AlertMode::NonZeroExit->value)
        ->and($monitor->email_alert_destination)->toBe($validEmail)
        ->and($monitor->webhook_alert_destination)->toBe($validWebhook);
});
