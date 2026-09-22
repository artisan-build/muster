<section data-testid="weekly-failure-digest">
    <h1>Muster weekly failure digest</h1>

    <div data-testid="weekly-failure-digest-context">
        <p>Recipient: {{ $recipient }}</p>
        <p>Week: {{ $payload['week_key'] }}</p>
        <p>UTC window: {{ $payload['window_start'] }} to {{ $payload['window_end'] }} (end excluded)</p>
    </div>

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

    <aside data-testid="weekly-failure-digest-release-note">
        <p>Here's what you just got: non-zero exits are now included in your weekly failure digest.</p>
    </aside>
</section>
