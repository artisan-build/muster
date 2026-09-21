@extends('layouts.muster', ['title' => 'Monitors'])

@section('content')
    <section class="page-heading" data-testid="monitors-heading">
        <div>
            <p class="eyebrow">Heartbeat registry</p>
            <h1>Monitors</h1>
        </div>
        <a class="button button-primary" href="{{ route('monitors.create') }}">New monitor</a>
    </section>

    <section class="panel" data-testid="monitors-list">
        @if($monitors->isEmpty())
            <div class="empty-state">
                <p>No monitors have been created yet.</p>
                <a href="{{ route('monitors.create') }}">Create the first monitor</a>
            </div>
        @else
            <div class="monitor-list">
                @foreach($monitors as $monitor)
                    <a class="monitor-row" href="{{ route('monitors.show', $monitor) }}">
                        <span>
                            <strong>{{ $monitor->name }}</strong>
                            <small>
                                @if($monitor->expression !== null)
                                    {{ $monitor->expression }}
                                @else
                                    Every {{ number_format($monitor->cadence_seconds) }}s + {{ number_format($monitor->grace_seconds) }}s grace
                                @endif
                            </small>
                        </span>
                        <span class="status {{ $monitor->is_enabled ? 'status-enabled' : 'status-disabled' }}">
                            {{ $monitor->is_enabled ? ucfirst($monitor->status) : 'Disabled' }}
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
