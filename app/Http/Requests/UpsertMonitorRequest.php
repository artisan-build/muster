<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpsertMonitorRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'cadence_seconds' => ['required', 'integer', 'min:1', 'max:31536000'],
            'grace_seconds' => ['required', 'integer', 'min:0', 'max:31536000'],
        ];
    }
}
