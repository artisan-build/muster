<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Monitor;
use App\ValueObjects\AlertModes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

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
            'email_alert_destination' => $this->destinationRules(
                'email_alert_modes',
                'email_alert_destination',
                'email:rfc',
                255,
            ),
            'webhook_alert_modes' => ['sometimes', 'array'],
            'webhook_alert_modes.*' => ['integer', 'distinct', 'in:1,2'],
            'webhook_alert_destination' => $this->destinationRules(
                'webhook_alert_modes',
                'webhook_alert_destination',
                'url:http,https',
                2048,
            ),
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

        if ($this->route('monitor') instanceof Monitor) {
            foreach (['email_alert_destination', 'webhook_alert_destination'] as $destination) {
                if (($attributes[$destination] ?? null) === null || $attributes[$destination] === '') {
                    unset($attributes[$destination]);
                }
            }
        }

        return $attributes;
    }

    /** @return list<string> */
    private function destinationRules(
        string $modeField,
        string $destinationField,
        string $formatRule,
        int $maximum,
    ): array {
        $modes = $this->input($modeField);

        if (is_array($modes) && $modes !== []) {
            $monitor = $this->route('monitor');
            $storedDestination = $monitor instanceof Monitor
                ? $monitor->getAttribute($destinationField)
                : null;
            $hasValidStoredDestination = is_string($storedDestination)
                && Validator::make(
                    [$destinationField => $storedDestination],
                    [$destinationField => ['required', $formatRule, "max:{$maximum}"]],
                )->passes();

            if ($hasValidStoredDestination && ! $this->filled($destinationField)) {
                return ['nullable', $formatRule, "max:{$maximum}"];
            }

            return ['required', $formatRule, "max:{$maximum}"];
        }

        return ['nullable', 'string', "max:{$maximum}"];
    }
}
