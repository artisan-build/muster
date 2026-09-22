<div class="form-grid">
    <label>
        <span>Name</span>
        <input name="name" value="{{ old('name', $monitor?->name) }}" required maxlength="255" autofocus>
        @error('name') <small class="field-error">{{ $message }}</small> @enderror
    </label>

    <label>
        <span>Expected cadence in seconds</span>
        <input type="number" name="cadence_seconds" value="{{ old('cadence_seconds', $monitor?->cadence_seconds ?? 300) }}" required min="1" max="31536000" inputmode="numeric">
        @error('cadence_seconds') <small class="field-error">{{ $message }}</small> @enderror
    </label>

    <label>
        <span>Grace period in seconds</span>
        <input type="number" name="grace_seconds" value="{{ old('grace_seconds', $monitor?->grace_seconds ?? 60) }}" required min="0" max="31536000" inputmode="numeric">
        @error('grace_seconds') <small class="field-error">{{ $message }}</small> @enderror
    </label>

    <fieldset data-testid="monitor-email-alert-configuration">
        <legend>Email alerts</legend>
        <label data-testid="monitor-email-alert-mode-missed">
            <input type="checkbox" name="email_alert_modes[]" value="{{ \App\Enums\AlertMode::Missed->value }}" @checked(in_array(\App\Enums\AlertMode::Missed->value, array_map('intval', (array) old('email_alert_modes', $monitor?->email_alert_modes->values() ?? [])), true))>
            <span>Missed and recovered</span>
        </label>
        <label data-testid="monitor-email-alert-mode-non-zero-exit">
            <input type="checkbox" name="email_alert_modes[]" value="{{ \App\Enums\AlertMode::NonZeroExit->value }}" @checked(in_array(\App\Enums\AlertMode::NonZeroExit->value, array_map('intval', (array) old('email_alert_modes', $monitor?->email_alert_modes->values() ?? [])), true))>
            <span>Non-zero exits</span>
        </label>
        <label>
            <span>Email destination</span>
            <input type="email" name="email_alert_destination" value="" maxlength="255" autocomplete="email">
            @error('email_alert_destination') <small class="field-error">{{ $message }}</small> @enderror
            @if ($monitor?->email_alert_destination !== null)
                <small data-testid="monitor-email-alert-destination-configured">Configured</small>
            @endif
        </label>
    </fieldset>

    <fieldset data-testid="monitor-webhook-alert-configuration">
        <legend>Webhook alerts</legend>
        <label data-testid="monitor-webhook-alert-mode-missed">
            <input type="checkbox" name="webhook_alert_modes[]" value="{{ \App\Enums\AlertMode::Missed->value }}" @checked(in_array(\App\Enums\AlertMode::Missed->value, array_map('intval', (array) old('webhook_alert_modes', $monitor?->webhook_alert_modes->values() ?? [])), true))>
            <span>Missed and recovered</span>
        </label>
        <label data-testid="monitor-webhook-alert-mode-non-zero-exit">
            <input type="checkbox" name="webhook_alert_modes[]" value="{{ \App\Enums\AlertMode::NonZeroExit->value }}" @checked(in_array(\App\Enums\AlertMode::NonZeroExit->value, array_map('intval', (array) old('webhook_alert_modes', $monitor?->webhook_alert_modes->values() ?? [])), true))>
            <span>Non-zero exits</span>
        </label>
        <label>
            <span>Webhook destination</span>
            <input type="url" name="webhook_alert_destination" value="" maxlength="2048" autocomplete="off">
            @error('webhook_alert_destination') <small class="field-error">{{ $message }}</small> @enderror
            @if ($monitor?->webhook_alert_destination !== null)
                <small data-testid="monitor-webhook-alert-destination-configured">Configured</small>
            @endif
        </label>
    </fieldset>
</div>

<div class="form-actions">
    <button class="button button-primary" type="submit">{{ $submitLabel }}</button>
    <a class="button" href="{{ $monitor ? route('monitors.show', $monitor) : route('monitors.index') }}">Cancel</a>
</div>
