@push('head')
    <link rel="stylesheet" href="{{ asset('build/assets/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('build/assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('muster.css') }}">
    @fluxAppearance
@endpush

<x-bfc-layout :title="filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel')">
    <x-slot:sidebar>
        <nav class="muster-navigation" aria-label="Primary navigation" data-testid="muster-sidebar-navigation">
            <a
                class="bfc-shell-link"
                href="{{ route('monitors.index') }}"
                data-testid="muster-navigation-monitors"
                @if(request()->routeIs('monitors.*')) aria-current="page" @endif
            >Monitors</a>
            <a
                class="bfc-shell-link"
                href="{{ route('bfc.ui.home') }}"
                data-testid="muster-navigation-account"
                @if(request()->routeIs('bfc.ui.*')) aria-current="page" @endif
            >Account</a>
        </nav>
    </x-slot:sidebar>

    <div class="page-shell">
        @yield('content')
    </div>
</x-bfc-layout>
