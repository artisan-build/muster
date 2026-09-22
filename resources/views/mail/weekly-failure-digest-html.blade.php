<section data-testid="weekly-failure-digest">
    <h1>Muster weekly failure digest</h1>

    <div data-testid="weekly-failure-digest-context">
        <p>Recipient: {{ $recipient }}</p>
        <p>Week: {{ $payload['week_key'] }}</p>
        <p>UTC window: {{ $payload['window_start'] }} to {{ $payload['window_end'] }} (end excluded)</p>
        <p data-testid="weekly-failure-digest-monitor-count">Monitored commands: {{ $payload['monitor_count'] }}</p>
    </div>

    @if ($payload['missed'] === [] && $payload['non_zero_exit'] === [])
        <section data-testid="weekly-failure-digest-all-clear">
            <h2>All clear</h2>
            <p>No missed or non-zero exit failures occurred across {{ $payload['monitor_count'] }} monitored command(s) this week.</p>
        </section>
    @endif

    <section data-testid="weekly-failure-digest-missed">
        <h2>Missed</h2>
        @forelse ($payload['missed'] as $failure)
            <article data-testid="weekly-failure-digest-row">
                <h3>{{ $failure['monitor_name'] }}</h3>
                @if ($failure['command'] !== null)
                    <p>Command: {{ $failure['command'] }}</p>
                @endif
                <p>Occurred at: {{ $failure['occurred_at'] }}</p>
            </article>
        @empty
            <p>None.</p>
        @endforelse
    </section>

    <section data-testid="weekly-failure-digest-non-zero-exit">
        <h2>Non-zero exits</h2>
        @forelse ($payload['non_zero_exit'] as $failure)
            <article data-testid="weekly-failure-digest-row">
                <h3>{{ $failure['monitor_name'] }}</h3>
                @if ($failure['command'] !== null)
                    <p>Command: {{ $failure['command'] }}</p>
                @endif
                <p>Exit code: {{ $failure['exit_code'] ?? 'unknown' }}</p>
                <p>Occurred at: {{ $failure['occurred_at'] }}</p>
            </article>
        @empty
            <p>None.</p>
        @endforelse
    </section>

    @if ($payload['missed'] !== [] || $payload['non_zero_exit'] !== [])
        <aside data-testid="weekly-failure-digest-release-note">
            <p>Here's what you just got: non-zero exits are now included in your weekly failure digest.</p>
        </aside>
    @endif

    <aside data-testid="weekly-failure-digest-urgent-alerts">
        <p>
            Need faster notice? Commands can be opted into urgent alerts.
            <a data-testid="weekly-failure-digest-monitor-configuration" href="{{ route('monitors.index') }}">Configure monitor alerts</a>.
        </p>
    </aside>
</section>
