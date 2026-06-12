<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? 'Laravel' }}</title>

<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
{{-- Load the webfont stylesheet without blocking first paint; swap in once loaded. --}}
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" media="print" onload="this.media='all'" />
<noscript>
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
</noscript>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
