<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ __('Jigsaw Preview') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; line-height: 1.6; }
        .wrap { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
        nav { padding: 1.5rem; border-right: 1px solid color-mix(in oklab, CanvasText 15%, transparent); }
        nav a { display: block; color: inherit; text-decoration: none; margin: .35rem 0; }
        nav a:hover { text-decoration: underline; }
        nav .child { margin-left: 1rem; font-size: .95rem; opacity: .9; }
        main { padding: 2rem 2.5rem; max-width: 48rem; }
        h1, h2, h3 { line-height: 1.25; }
        code { font-family: ui-monospace, monospace; font-size: .9em; }
        pre { overflow: auto; padding: 1rem; background: color-mix(in oklab, CanvasText 6%, Canvas); }
        .badge { display: inline-block; font-size: .75rem; opacity: .6; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <nav>
            <strong>Jigsaw</strong>
            <div class="badge">{{ __('Live Preview') }}</div>
            @foreach ($navigation as $label => $item)
                @php
                    $url = is_array($item) ? ($item['url'] ?? null) : $item;
                    $children = is_array($item) ? ($item['children'] ?? []) : [];
                    $href = $url && ! str_starts_with($url, 'http') ? url('/'.$url) : $url;
                @endphp
                @if ($href)
                    <a href="{{ $href }}">{{ $label }}</a>
                @else
                    <div>{{ $label }}</div>
                @endif
                @foreach ($children as $childLabel => $childItem)
                    @php $childUrl = is_array($childItem) ? ($childItem['url'] ?? '') : $childItem; @endphp
                    @if ($childUrl)
                        <a class="child" href="{{ str_starts_with($childUrl, 'http') ? $childUrl : url('/'.$childUrl) }}">{{ $childLabel }}</a>
                    @endif
                @endforeach
            @endforeach
        </nav>
        <main>
            {!! $html !!}
        </main>
    </div>
</body>
</html>
