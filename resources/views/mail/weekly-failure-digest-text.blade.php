[weekly-failure-digest]
Muster weekly failure digest

[weekly-failure-digest-context]
Recipient: {{ $recipient }}
Week: {{ $payload['week_key'] }}
UTC window: {{ $payload['window_start'] }} to {{ $payload['window_end'] }} (end excluded)
[weekly-failure-digest-monitor-count]
Monitored commands: {{ $payload['monitor_count'] }}

@if ($payload['missed'] === [] && $payload['non_zero_exit'] === [])
[weekly-failure-digest-all-clear]
All clear: No missed or non-zero exit failures occurred across {{ $payload['monitor_count'] }} monitored command(s) this week.

@endif
[weekly-failure-digest-missed]
Missed
@forelse ($payload['missed'] as $failure)
[weekly-failure-digest-row]
Monitor: {{ $failure['monitor_name'] }}
@if ($failure['command'] !== null)
Command: {{ $failure['command'] }}
@endif
Occurred at: {{ $failure['occurred_at'] }}

@empty
None.

@endforelse
[weekly-failure-digest-non-zero-exit]
Non-zero exits
@forelse ($payload['non_zero_exit'] as $failure)
[weekly-failure-digest-row]
Monitor: {{ $failure['monitor_name'] }}
@if ($failure['command'] !== null)
Command: {{ $failure['command'] }}
@endif
Exit code: {{ $failure['exit_code'] ?? 'unknown' }}
Occurred at: {{ $failure['occurred_at'] }}

@empty
None.

@endforelse
@if ($payload['missed'] !== [] || $payload['non_zero_exit'] !== [])
[weekly-failure-digest-release-note]
Here's what you just got: non-zero exits are now included in your weekly failure digest.
@endif

[weekly-failure-digest-urgent-alerts]
Need faster notice? Commands can be opted into urgent alerts.
[weekly-failure-digest-monitor-configuration]
Configure monitor alerts: {{ route('monitors.index') }}
