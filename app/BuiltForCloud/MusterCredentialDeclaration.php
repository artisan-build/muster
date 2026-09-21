<?php

declare(strict_types=1);

namespace App\BuiltForCloud;

use App\Models\CredentialPartition;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\BoundCredentialScope;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresCredentialAuthorizationProfiles;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresHeadlineStat;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuthorizationOwnership;
use ArtisanBuild\BuiltForCloud\CredentialAuthorizationProfile;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Vitals\HeadlineStat;
use ArtisanBuild\BuiltForCloud\Vitals\HeadlineUnit;
use Illuminate\Http\Request;

final class MusterCredentialDeclaration implements CredentialDeclaration, DeclaresCredentialAuthorizationProfiles, DeclaresHeadlineStat
{
    public const ?string HEADLINE_VOCABULARY = MonitorHeadlineLabel::class;

    public function resolveSubject(Request $request): ?Subject
    {
        return null;
    }

    public function authorize(Credential $credential, ?string $ability, Request $request): bool
    {
        if ($credential->subject_type === SubjectType::Operator
            && $credential->purpose === CredentialPurpose::DashboardMetadata
            && $credential->status === CredentialStatus::Active
            && $credential->revoked_at === null
            && ($credential->expires_at === null || $credential->expires_at->isFuture())
            && ($credential->abilities ?? []) === [OperatorAbility::MetadataRead->value]) {
            return $ability === null || $ability === OperatorAbility::MetadataRead->value;
        }

        $partition = CredentialPartition::local();

        return $credential->subject_type === $partition->subject_type
            && hash_equals($credential->subject_ref, $partition->subject_ref)
            && $credential->purpose === CredentialPurpose::Consumption
            && $credential->user_id === null
            && ($credential->abilities ?? []) === [];
    }

    public function credentialAuthorizationProfiles(Request $request): array
    {
        $partition = CredentialPartition::local();

        return array_map(
            static fn (string $appPurpose): CredentialAuthorizationProfile => new CredentialAuthorizationProfile(
                $appPurpose,
                new BoundCredentialScope(
                    $appPurpose,
                    $partition->subject(),
                    $partition->installation_ref,
                    $partition->application_ref,
                    $partition->audience,
                ),
                CredentialAuthorizationOwnership::Installation,
                [],
                null,
                600,
                5,
            ),
            ['muster.check-in', 'muster.history.read'],
        );
    }

    public function headlineStat(): HeadlineStat
    {
        return new HeadlineStat(
            Monitor::query()->where('is_enabled', true)->count(),
            MonitorHeadlineLabel::EnabledMonitors,
            HeadlineUnit::Count,
        );
    }
}
