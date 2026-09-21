<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.svg" type="image/svg+xml">

<link rel="stylesheet" href="{{ asset('build/assets/fonts.css') }}">
<link rel="stylesheet" href="{{ asset('build/assets/app.css') }}">
@fluxAppearance
