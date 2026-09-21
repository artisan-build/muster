<?php

declare(strict_types=1);

use App\BuiltForCloud\MonitorHeadlineLabel;
use App\BuiltForCloud\MusterCredentialDeclaration;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Vitals\CollectVitals;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

uses(ContractAssertions::class);

it('refuses credential authorization until the check-in policy is implemented', function (): void {
    $credential = new Credential([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::DashboardMetadata,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-dashboard-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
        'status' => CredentialStatus::Active,
    ]);
    $declaration = resolve(CredentialDeclaration::class);

    expect($declaration)->toBeInstanceOf(MusterCredentialDeclaration::class)
        ->and($declaration->authorize(
            $credential,
            OperatorAbility::MetadataRead->value,
            Request::create('/bfc/console/vitals'),
        ))->toBeFalse();
});

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

    expect(resolve(CredentialDeclaration::class))->toBeInstanceOf(MusterCredentialDeclaration::class)
        ->and(MusterCredentialDeclaration::HEADLINE_VOCABULARY)->toBe(MonitorHeadlineLabel::class);

    $payload = resolve(CollectVitals::class)();
    $headline = $payload->toArray()['headline'];

    expect($headline)->toBe([
        'value' => 2,
        'label' => MonitorHeadlineLabel::EnabledMonitors->value,
        'unit' => 'count',
    ]);

    $response = TestResponse::fromBaseResponse(response()->json($payload->toArray()));

    $this->assertBuiltForCloudMetadataEndpoint($response, 'GET /bfc/console/vitals');
});
