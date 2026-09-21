<?php

declare(strict_types=1);

namespace App\BuiltForCloud;

use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresHeadlineStat;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\Vitals\HeadlineStat;
use ArtisanBuild\BuiltForCloud\Vitals\HeadlineUnit;
use Illuminate\Http\Request;

final class MusterCredentialDeclaration implements CredentialDeclaration, DeclaresHeadlineStat
{
    public const ?string HEADLINE_VOCABULARY = MonitorHeadlineLabel::class;

    public function resolveSubject(Request $request): ?Subject
    {
        return null;
    }

    public function authorize(Credential $credential, ?string $ability, Request $request): bool
    {
        return false;
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
