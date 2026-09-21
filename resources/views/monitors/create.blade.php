@extends('layouts.muster', ['title' => 'New monitor'])

@section('content')
    <section class="page-heading" data-testid="monitor-create-heading">
        <div>
            <p class="eyebrow">New heartbeat</p>
            <h1>Create monitor</h1>
        </div>
    </section>

    <form class="panel form-panel" method="POST" action="{{ route('monitors.store') }}" data-testid="monitor-create-form">
        @csrf
        @include('monitors._form', ['monitor' => null, 'submitLabel' => 'Create monitor'])
    </form>
@endsection
