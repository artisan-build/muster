<!DOCTYPE html>
<html lang="en">
    <head>
        @include('partials.head', ['title' => $title ?? null])
        <link rel="stylesheet" href="{{ asset('muster.css') }}">
    </head>
    <body>
        <header class="site-header">
            <a class="brand" href="{{ route('monitors.index') }}">
                <x-app-logo-icon class="brand-mark" />
                <span>Muster</span>
            </a>
            <nav aria-label="Primary navigation">
                <a href="{{ route('monitors.index') }}">Monitors</a>
                <a href="{{ route('bfc.ui.home') }}">Account</a>
            </nav>
        </header>

        <main class="page-shell">
            @yield('content')
        </main>
    </body>
</html>
