<?php

declare(strict_types=1);

use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

function signInForSidebarShell(): void
{
    $user = User::query()->create([
        'name' => 'Test Created Sidebar Operator',
        'email' => 'sidebar-'.bin2hex(random_bytes(4)).'@example.test',
        'password' => 'test-password',
    ]);
    $user->refresh();

    test()->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);
}

function sidebarShellXPath(TestResponse $response): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

it('renders every monitor page in the shared shell with one complete app navigation', function (string $routeName, string $contentMarker): void {
    signInForSidebarShell();

    $monitor = Monitor::query()->create([
        'name' => 'Test Created Sidebar Monitor',
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
    ]);

    $parameters = in_array($routeName, ['monitors.show', 'monitors.edit'], true) ? [$monitor] : [];
    $response = $this->get(route($routeName, $parameters))
        ->assertOk()
        ->assertSee('data-testid="bfc-sidebar-slot"', false)
        ->assertDontSee('data-testid="bfc-sidebar-fallback"', false)
        ->assertSee('data-testid="bfc-main"', false)
        ->assertSee('data-testid="'.$contentMarker.'"', false);

    $xpath = sidebarShellXPath($response);
    $navigation = $xpath->query('//*[@data-testid="bfc-sidebar-slot"]//nav[@data-testid="muster-sidebar-navigation"]');

    expect($navigation)->not->toBeFalse()
        ->and($navigation->length)->toBe(1)
        ->and($xpath->query('//nav[@aria-label="Primary navigation"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="bfc-main"]//*[@data-testid="'.$contentMarker.'"]')->length)->toBe(1)
        ->and($xpath->query('//*[@class="site-header"]')->length)->toBe(0);

    $stylesheets = [];
    foreach ($xpath->query('/html/head/link[@rel="stylesheet"]') as $stylesheet) {
        $stylesheets[] = $stylesheet->getAttribute('href');
    }

    expect($stylesheets)->toContain(asset('build/assets/fonts.css'))
        ->and($stylesheets)->toContain(asset('build/assets/app.css'))
        ->and($stylesheets)->toContain(asset('muster.css'));

    $nav = $navigation->item(0);
    expect($nav)->toBeInstanceOf(DOMElement::class);

    $links = $xpath->query('./a', $nav);
    expect($links)->not->toBeFalse()
        ->and($links->length)->toBe(2)
        ->and(trim($links->item(0)->textContent))->toBe('Monitors')
        ->and($links->item(0)->getAttribute('href'))->toBe(route('monitors.index'))
        ->and($links->item(0)->getAttribute('aria-current'))->toBe('page')
        ->and(trim($links->item(1)->textContent))->toBe('Account')
        ->and($links->item(1)->getAttribute('href'))->toBe(route('bfc.ui.home'))
        ->and($links->item(1)->hasAttribute('aria-current'))->toBeFalse();
})->with([
    'monitor index' => ['monitors.index', 'monitors-heading'],
    'monitor creation' => ['monitors.create', 'monitor-create-heading'],
    'monitor detail' => ['monitors.show', 'monitor-detail-heading'],
    'monitor editing' => ['monitors.edit', 'monitor-edit-heading'],
]);

it('marks the account destination current in an account route context', function (): void {
    signInForSidebarShell();

    Route::get('/_tests/sidebar-shell/account', fn () => view('monitors.index', ['monitors' => Monitor::query()->get()]))
        ->name('bfc.ui.sidebar-shell-test');

    $response = $this->get('/_tests/sidebar-shell/account')->assertOk();
    $xpath = sidebarShellXPath($response);
    $navigation = $xpath->query('//*[@data-testid="muster-sidebar-navigation"]');

    expect($navigation)->not->toBeFalse()
        ->and($navigation->length)->toBe(1);

    $nav = $navigation->item(0);
    expect($nav)->toBeInstanceOf(DOMElement::class);

    $monitors = $xpath->query('./a[@data-testid="muster-navigation-monitors"]', $nav)->item(0);
    $account = $xpath->query('./a[@data-testid="muster-navigation-account"]', $nav)->item(0);

    expect($monitors)->toBeInstanceOf(DOMElement::class)
        ->and($monitors->hasAttribute('aria-current'))->toBeFalse()
        ->and($account)->toBeInstanceOf(DOMElement::class)
        ->and($account->getAttribute('aria-current'))->toBe('page');
});
