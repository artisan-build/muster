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
</div>

<div class="form-actions">
    <button class="button button-primary" type="submit">{{ $submitLabel }}</button>
    <a class="button" href="{{ $monitor ? route('monitors.show', $monitor) : route('monitors.index') }}">Cancel</a>
</div>
