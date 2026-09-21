<?php

declare(strict_types=1);

namespace App\Models;

use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $local_key
 * @property SubjectType $subject_type
 * @property string $subject_ref
 * @property string $installation_ref
 * @property string $application_ref
 * @property string $audience
 */
final class CredentialPartition extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'subject_type',
        'subject_ref',
        'installation_ref',
        'application_ref',
        'audience',
    ];

    public static function local(): self
    {
        return self::query()->where('local_key', 'current')->sole();
    }

    public function subject(): Subject
    {
        return new Subject($this->subject_type, $this->subject_ref);
    }

    /** @return HasMany<Monitor, $this> */
    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_type' => SubjectType::class,
        ];
    }
}
