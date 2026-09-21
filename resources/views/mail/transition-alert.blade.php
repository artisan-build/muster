Monitor: {{ $payload['monitor']['name'] }} (#{{ $payload['monitor']['id'] }})
Transition: {{ $payload['event']['transition'] }}
Event: {{ $payload['event']['id'] }}
Occurred at: {{ $payload['event']['occurred_at'] }}
Last check-in: {{ $payload['monitor']['last_check_in_at'] ?? 'none' }}
Next due: {{ $payload['monitor']['next_due_at'] ?? 'none' }}
