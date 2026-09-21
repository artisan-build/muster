<?php

use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\User;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(ContractAssertions::class);

it('matches the canonical Muster manifest and purpose contracts', function (): void {
    $this->assertBuiltForCloudManifestMatches([
        'name' => 'Muster',
        'slug' => 'muster',
        'description' => 'Self-hosted heartbeat and cron monitoring for scheduled tasks and background services.',
        'icon' => 'https://scalpels.app/icons/muster.svg',
        'product_url' => 'https://scalpels.app/products/muster',
    ]);

    expect(resolve(AppPurposeRegistry::class)->purpose('muster.check-in'))
        ->toBe(CredentialPurpose::Consumption)
        ->and(config('built-for-cloud.ui'))->toBe([
            'landing_page' => true,
            'member_management' => true,
            'personal_credentials' => true,
            'installation_credentials' => true,
            'session_management' => true,
            'managed_transitions' => true,
            'credential_purposes' => ['muster.check-in'],
        ]);
});

it('merges package defaults and auto-discovers the released provider', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $constraint = data_get($composer, 'require.artisan-build/built-for-cloud');
    $version = InstalledVersions::getVersion('artisan-build/built-for-cloud');

    expect($constraint)->toBe('^0.15')
        ->and($version)->toBeString();
    assert(is_string($constraint));
    assert(is_string($version));

    expect(config('built-for-cloud.manifest'))->toBe([
        'name' => 'Muster',
        'slug' => 'muster',
        'description' => 'Self-hosted heartbeat and cron monitoring for scheduled tasks and background services.',
        'icon' => 'https://scalpels.app/icons/muster.svg',
        'product_url' => 'https://scalpels.app/products/muster',
    ])->and(config('built-for-cloud.ui'))->toBe([
        'landing_page' => true,
        'member_management' => true,
        'personal_credentials' => true,
        'installation_credentials' => true,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => ['muster.check-in'],
    ])->and(config('built-for-cloud.product'))->toBe(config('app.name'))
        ->and(config('built-for-cloud.credentials.guard'))->toBe('bfc')
        ->and(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and(app()->getLoadedProviders())->toHaveKey(BuiltForCloudServiceProvider::class, true)
        ->and(version_compare($version, '0.15.0', '>='))->toBeTrue()
        ->and(version_compare($version, '0.16.0', '<'))->toBeTrue();
});

it('runs fresh package-owned migrations on sqlite', function (): void {
    expect(Artisan::call('migrate:fresh', [
        '--database' => 'sqlite',
        '--force' => true,
    ]))->toBe(0)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('bfc_authority'))->toBeTrue()
        ->and(Schema::hasTable('credentials'))->toBeTrue()
        ->and(Schema::hasTable('monitors'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'normalized_email'))->toBeTrue()
        ->and(glob(database_path('migrations/*users*')) ?: [])->toBe([])
        ->and(class_exists('App\\Models\\User'))->toBeFalse();
});
