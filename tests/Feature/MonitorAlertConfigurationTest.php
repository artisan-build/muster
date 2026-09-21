<?php

declare(strict_types=1);

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
        ->assertSee('name="email_alerts_enabled"', false)
        ->assertSee('name="webhook_alerts_enabled"', false);
});

it('stores independently enabled alert destinations encrypted at rest', function (): void {
    signInForAlertConfiguration();
    $monitor = createAlertConfigurationMonitor();
    $email = 'billing-alerts-'.bin2hex(random_bytes(3)).'@example.test';
    $webhook = 'https://hooks.example.test/muster/'.bin2hex(random_bytes(8)).'?token='.bin2hex(random_bytes(8));

    $this->put(route('monitors.update', $monitor), [
        'name' => $monitor->name,
        'cadence_seconds' => $monitor->cadence_seconds,
        'grace_seconds' => $monitor->grace_seconds,
        'email_alerts_enabled' => '0',
        'email_alert_destination' => $email,
        'webhook_alerts_enabled' => '1',
        'webhook_alert_destination' => $webhook,
    ])->assertRedirect(route('monitors.show', $monitor));

    $monitor->refresh();
    $stored = DB::table('monitors')->where('id', $monitor->id)->first();

    expect($monitor->email_alerts_enabled)->toBeFalse()
        ->and($monitor->email_alert_destination)->toBe($email)
        ->and($monitor->webhook_alerts_enabled)->toBeTrue()
        ->and($monitor->webhook_alert_destination)->toBe($webhook)
        ->and($stored?->email_alert_destination)->not->toBe($email)
        ->and($stored?->webhook_alert_destination)->not->toBe($webhook);
});

it('rejects malformed destinations without replacing valid configuration or echoing them in errors', function (): void {
    signInForAlertConfiguration();
    $monitor = createAlertConfigurationMonitor();
    $validEmail = 'valid-alerts@example.test';
    $validWebhook = 'https://hooks.example.test/valid/'.bin2hex(random_bytes(8));

    $monitor->update([
        'email_alerts_enabled' => true,
        'email_alert_destination' => $validEmail,
        'webhook_alerts_enabled' => true,
        'webhook_alert_destination' => $validWebhook,
    ]);

    $invalidEmail = 'malformed-email-secret';
    $invalidWebhook = 'ftp://hooks.example.test/'.bin2hex(random_bytes(8)).'?credential=secret-fragment';
    $response = $this->from(route('monitors.edit', $monitor))->put(route('monitors.update', $monitor), [
        'name' => 'Test Created Mutated Name',
        'cadence_seconds' => 900,
        'grace_seconds' => 90,
        'email_alerts_enabled' => '1',
        'email_alert_destination' => $invalidEmail,
        'webhook_alerts_enabled' => '1',
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
        ->and($monitor->email_alert_destination)->toBe($validEmail)
        ->and($monitor->webhook_alert_destination)->toBe($validWebhook);
});
