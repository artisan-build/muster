<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\ValueObjects\AlertModes;
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
            'email_alert_modes' => ['sometimes', 'array'],
            'email_alert_modes.*' => ['integer', 'distinct', 'in:1,2'],
            'email_alert_destination' => $this->destinationRules('email_alert_modes', 'email:rfc', 255),
            'webhook_alert_modes' => ['sometimes', 'array'],
            'webhook_alert_modes.*' => ['integer', 'distinct', 'in:1,2'],
            'webhook_alert_destination' => $this->destinationRules('webhook_alert_modes', 'url:http,https', 2048),
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

    /** @return array<string, mixed> */
    public function monitorAttributes(): array
    {
        $attributes = $this->validated();
        $attributes['email_alert_modes'] = AlertModes::fromValues($attributes['email_alert_modes'] ?? []);
        $attributes['webhook_alert_modes'] = AlertModes::fromValues($attributes['webhook_alert_modes'] ?? []);

        return $attributes;
    }

    /** @return list<string> */
    private function destinationRules(string $modeField, string $formatRule, int $maximum): array
    {
        $modes = $this->input($modeField);

        if (is_array($modes) && $modes !== []) {
            return ['required', $formatRule, "max:{$maximum}"];
        }

        return ['nullable', 'string', "max:{$maximum}"];
    }
}
