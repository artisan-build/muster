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
            'email_alerts_enabled' => ['sometimes', 'boolean'],
            'email_alert_destination' => ['nullable', 'required_if:email_alerts_enabled,1', 'email:rfc', 'max:255'],
            'webhook_alerts_enabled' => ['sometimes', 'boolean'],
            'webhook_alert_destination' => ['nullable', 'required_if:webhook_alerts_enabled,1', 'url:http,https', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email_alert_destination.email' => 'Enter a valid email alert destination.',
            'webhook_alert_destination.url' => 'Enter a valid HTTP or HTTPS webhook alert destination.',
        ];
    }
}
