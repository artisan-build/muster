<?php

declare(strict_types=1);

use App\BuiltForCloud\MonitorHeadlineLabel;
use App\BuiltForCloud\MusterCredentialDeclaration;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use Illuminate\Http\Request;

uses(ContractAssertions::class, WithCredentials::class);

it('authorizes only the active least-privilege operator dashboard shape', function (): void {
    $reader = $this->mintCredential([
        'purpose' => CredentialPurpose::DashboardMetadata,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-dashboard-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
    ]);
    $combined = $this->mintCredential([
        'purpose' => CredentialPurpose::DashboardMetadata,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-combined-dashboard-reader',
        'abilities' => [OperatorAbility::MetadataRead->value, OperatorAbility::CredentialRead->value],
    ]);
    $wrongPurpose = $this->mintCredential([
        'purpose' => CredentialPurpose::OperatorManagement,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-wrong-purpose-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
    ]);
    $pending = $this->mintCredential([
        'purpose' => CredentialPurpose::DashboardMetadata,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-pending-dashboard-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
        'status' => CredentialStatus::Pending,
    ]);
    $revoked = $this->mintCredential([
        'purpose' => CredentialPurpose::DashboardMetadata,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-revoked-dashboard-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
        'revoked_at' => now(),
    ]);
    $expired = $this->mintCredential([
        'purpose' => CredentialPurpose::DashboardMetadata,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-expired-dashboard-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
        'expires_at' => now()->subSecond(),
    ]);
    $declaration = resolve(CredentialDeclaration::class);
    $request = Request::create('/bfc/console/vitals');

    expect($declaration)->toBeInstanceOf(MusterCredentialDeclaration::class)
        ->and($declaration->authorize(
            $reader->credential,
            null,
            $request,
        ))->toBeTrue()
        ->and($declaration->authorize(
            $reader->credential,
            OperatorAbility::MetadataRead->value,
            $request,
        ))->toBeTrue()
        ->and($declaration->authorize(
            $reader->credential,
            OperatorAbility::CredentialRead->value,
            $request,
        ))->toBeFalse()
        ->and($declaration->authorize($combined->credential, null, $request))->toBeFalse()
        ->and($declaration->authorize($wrongPurpose->credential, null, $request))->toBeFalse()
        ->and($declaration->authorize($pending->credential, null, $request))->toBeFalse()
        ->and($declaration->authorize($revoked->credential, null, $request))->toBeFalse()
        ->and($declaration->authorize($expired->credential, null, $request))->toBeFalse();
});

it('serves the enabled-only headline through the real package guard and vitals route', function (): void {
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

    $reader = $this->mintCredential([
        'purpose' => CredentialPurpose::DashboardMetadata,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-live-vitals-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
    ]);
    $combined = $this->mintCredential([
        'purpose' => CredentialPurpose::DashboardMetadata,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-overprivileged-vitals-reader',
        'abilities' => [OperatorAbility::MetadataRead->value, OperatorAbility::CredentialRead->value],
    ]);
    $wrongPurpose = $this->mintCredential([
        'purpose' => CredentialPurpose::OperatorManagement,
        'subject_type' => SubjectType::Operator,
        'subject_ref' => 'test-created-management-reader',
        'abilities' => [OperatorAbility::MetadataRead->value],
    ]);

    $response = $this->getJson('/bfc/console/vitals', [
        'Authorization' => $reader->bearerHeader(),
    ])->assertOk()
        ->assertJsonPath('headline.value', 2)
        ->assertJsonPath('headline.label', MonitorHeadlineLabel::EnabledMonitors->value)
        ->assertJsonPath('headline.unit', 'count');

    $this->getJson('/bfc/console/vitals', [
        'Authorization' => $combined->bearerHeader(),
    ])->assertForbidden();
    $this->getJson('/bfc/console/vitals', [
        'Authorization' => $wrongPurpose->bearerHeader(),
    ])->assertUnauthorized();
    $this->getJson('/bfc/console/vitals')->assertUnauthorized();

    $this->assertBuiltForCloudMetadataEndpoint($response, 'GET /bfc/console/vitals');
});
