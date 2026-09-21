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
        <input type="hidden" name="email_alerts_enabled" value="0">
        <label>
            <input type="checkbox" name="email_alerts_enabled" value="1" @checked(old('email_alerts_enabled', $monitor?->email_alerts_enabled ?? false))>
            <span>Send missed and recovered alerts by email</span>
        </label>
        <label>
            <span>Email destination</span>
            <input type="email" name="email_alert_destination" value="{{ $monitor?->email_alert_destination }}" maxlength="255" autocomplete="email">
            @error('email_alert_destination') <small class="field-error">{{ $message }}</small> @enderror
        </label>
    </fieldset>

    <fieldset data-testid="monitor-webhook-alert-configuration">
        <legend>Webhook alerts</legend>
        <input type="hidden" name="webhook_alerts_enabled" value="0">
        <label>
            <input type="checkbox" name="webhook_alerts_enabled" value="1" @checked(old('webhook_alerts_enabled', $monitor?->webhook_alerts_enabled ?? false))>
            <span>Send missed and recovered alerts by webhook</span>
        </label>
        <label>
            <span>Webhook destination</span>
            <input type="url" name="webhook_alert_destination" value="{{ $monitor?->webhook_alert_destination }}" maxlength="2048" autocomplete="off">
            @error('webhook_alert_destination') <small class="field-error">{{ $message }}</small> @enderror
        </label>
    </fieldset>
</div>

<div class="form-actions">
    <button class="button button-primary" type="submit">{{ $submitLabel }}</button>
    <a class="button" href="{{ $monitor ? route('monitors.show', $monitor) : route('monitors.index') }}">Cancel</a>
</div>
