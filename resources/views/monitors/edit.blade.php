@extends('layouts.muster', ['title' => 'Edit '.$monitor->name])

@section('content')
    <section class="page-heading" data-testid="monitor-edit-heading">
        <div>
            <p class="eyebrow">Monitor settings</p>
            <h1>Edit {{ $monitor->name }}</h1>
        </div>
    </section>

    <form class="panel form-panel" method="POST" action="{{ route('monitors.update', $monitor) }}" data-testid="monitor-edit-form">
        @csrf
        @method('PUT')
        @include('monitors._form', ['monitor' => $monitor, 'submitLabel' => 'Save changes'])
    </form>
@endsection
