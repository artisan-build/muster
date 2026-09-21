@extends('layouts.muster', ['title' => $monitor->name])

@section('content')
    <section class="page-heading" data-testid="monitor-detail-heading">
        <div>
            <p class="eyebrow">Monitor detail</p>
            <h1>{{ $monitor->name }}</h1>
        </div>
        <a class="button" href="{{ route('monitors.edit', $monitor) }}">Edit monitor</a>
    </section>

    <section class="detail-grid" data-testid="monitor-detail-summary">
        <article class="panel signal-card">
            <span class="status {{ $monitor->is_enabled ? 'status-enabled' : 'status-disabled' }}">
                {{ $monitor->is_enabled ? ucfirst($monitor->status) : 'Disabled' }}
            </span>
            <dl>
                <div><dt>Expected cadence</dt><dd>{{ number_format($monitor->cadence_seconds) }} seconds</dd></div>
                <div><dt>Grace period</dt><dd>{{ number_format($monitor->grace_seconds) }} seconds</dd></div>
                <div><dt>Last check-in</dt><dd>{{ $monitor->last_check_in_at?->diffForHumans() ?? 'Waiting for first check-in' }}</dd></div>
                <div><dt>Next due</dt><dd>{{ $monitor->next_due_at?->diffForHumans() ?? 'Starts after first check-in' }}</dd></div>
            </dl>
        </article>

        <aside class="panel action-card">
            @if($monitor->is_enabled)
                <form method="POST" action="{{ route('monitors.disable', $monitor) }}">
                    @csrf
                    @method('PATCH')
                    <button class="button button-danger" type="submit">Disable monitor</button>
                </form>
            @else
                <form method="POST" action="{{ route('monitors.enable', $monitor) }}">
                    @csrf
                    @method('PATCH')
                    <button class="button button-primary" type="submit">Enable monitor</button>
                </form>
            @endif
        </aside>
    </section>

    <section class="panel" data-testid="monitor-activity-history">
        <p class="eyebrow">Recent activity</p>
        <h2>Activity history</h2>

        @if($monitor->events->isEmpty())
            <p>No activity recorded yet.</p>
        @else
            <ol data-testid="monitor-activity-events">
                @foreach($monitor->events as $event)
                    <li data-testid="monitor-activity-event">
                        <span>{{ str($event->event_type)->replace('_', ' ')->headline() }}</span>
                        <time datetime="{{ $event->occurred_at->toISOString() }}">
                            {{ $event->occurred_at->toDayDateTimeString() }}
                        </time>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endsection
