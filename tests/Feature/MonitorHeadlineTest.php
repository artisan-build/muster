<?php

declare(strict_types=1);

use App\BuiltForCloud\MonitorHeadlineLabel;
use App\BuiltForCloud\MusterCredentialDeclaration;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;

uses(ContractAssertions::class, WithCredentials::class);

it('reports only enabled monitors through the declared bounded headline', function (): void {
    foreach (['Test Created API Sync', 'Test Created Invoice Sweep'] as $name) {
        Monitor::query()->create([
            'name' => $name,
            'cadence_seconds' => 300,
            'grace_seconds' => 30,
        ]);
    }

    $disabled = Monitor::query()->create([
        'name' => 'Test Created Disabled Import',
        'cadence_seconds' => 600,
        'grace_seconds' => 60,
    ]);
    $disabled->forceFill(['is_enabled' => false])->save();

    $reader = $this->mintCredential([
        'purpose' => CredentialPurpose::DashboardMetadata,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-vitals-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
    ]);

    expect(resolve(CredentialDeclaration::class))->toBeInstanceOf(MusterCredentialDeclaration::class)
        ->and(MusterCredentialDeclaration::HEADLINE_VOCABULARY)->toBe(MonitorHeadlineLabel::class);

    $response = $this->getJson('/bfc/console/vitals', [
        'Authorization' => $reader->bearerHeader(),
    ])->assertOk()
        ->assertJsonPath('headline.value', 2)
        ->assertJsonPath('headline.label', MonitorHeadlineLabel::EnabledMonitors->value)
        ->assertJsonPath('headline.unit', 'count');

    $this->assertBuiltForCloudMetadataEndpoint($response, 'GET /bfc/console/vitals');
});
