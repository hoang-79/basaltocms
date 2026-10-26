{{-- Rechtstext öffentlich (G-07, TB 11 Scheibe 3): eigenständig, ohne Skript, Sitzung und Fremdressource --}}
<!DOCTYPE html>
<html lang="{{ $page['locale'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($page['draft'])
        {{-- Entwürfe nicht in Suchmaschinen --}}
        <meta name="robots" content="noindex">
    @endif
    <title>{{ $page['title'] }} · Basalto</title>
    <style>
        :root { --basalt: #23262b; --basalt-light: #3b3f46; --lava: #e4572e; --ash-dark: #62676e; --ash-light: #e3e6ea; --paper: #f6f4f0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--paper); color: var(--basalt); font: 15px/1.65 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif; }
        a { color: inherit; }
        header, main, nav.texte { max-width: 72rem; margin: 0 auto; padding: 0 20px; }
        header { display: flex; flex-wrap: wrap; gap: 8px 20px; align-items: baseline; justify-content: space-between; padding-top: 22px; padding-bottom: 10px; }
        .marke { font-weight: 700; font-size: 18px; text-decoration: none; }
        .marke span { color: var(--lava); }
        .sprachen { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 13px; list-style: none; margin: 0; padding: 0; }
        .sprachen [aria-current] { font-weight: 700; text-decoration: none; }
        .raster { display: grid; gap: 20px; grid-template-columns: minmax(0, 1fr); padding-bottom: 40px; }
        @media (min-width: 52rem) { .raster { grid-template-columns: 14rem minmax(0, 1fr); } }
        .texte ul { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 2px 14px; font-size: 13.5px; }
        @media (min-width: 52rem) { .texte ul { display: grid; gap: 2px; } }
        .texte a { display: block; padding: 5px 8px; border-radius: 6px; text-decoration: none; color: var(--basalt-light); }
        .texte a[aria-current] { background: #fff; color: var(--basalt); font-weight: 600; box-shadow: inset 3px 0 0 var(--lava); }
        .texte h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--ash-dark); margin: 4px 8px 6px; }
        article { background: #fff; border: 1px solid var(--ash-light); border-radius: 10px; padding: 22px 26px; max-width: 75ch; overflow-wrap: break-word; hyphens: auto; }
        h1 { font-size: 28px; line-height: 1.2; margin: 0 0 14px; }
        article h2 { font-size: 18px; margin: 1.5em 0 .4em; }
        article h3 { font-size: 15.5px; margin: 1.2em 0 .3em; }
        article p, article ul, article ol, article table { margin: 0 0 .85em; }
        article ul { padding-left: 1.2em; }
        article ol { padding-left: 1.4em; }
        article li { margin: .2em 0; }
        article code { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 13px; }
        article blockquote { margin: 0 0 1em; padding: 8px 14px; border-left: 3px solid var(--lava); background: #fdf0eb; }
        article blockquote p { margin: 0; }
        article table { width: 100%; border-collapse: collapse; font-size: 14px; }
        article th, article td { padding: 5px 10px 5px 0; border-bottom: 1px solid var(--ash-light); text-align: left; vertical-align: top; }
        article hr { border: 0; border-top: 1px solid var(--ash-light); margin: 1.5em 0; }
        @media (max-width: 40rem) {
            article { padding: 16px; } h1 { font-size: 23px; }
            /* Tabellen stapeln statt quetschen: je Zeile ein Block, die erste Zelle als Titel */
            article table, article tbody, article tr, article td { display: block; }
            article thead { display: none; }
            article tr { padding: 6px 0; border-bottom: 1px solid var(--ash-light); }
            article td { padding: 0; border: 0; }
            article td:first-child { font-weight: 600; }
        }
    </style>
</head>
<body>
<header>
    <a class="marke" href="{{ \Basalto\Core\Vendor::url() }}">Basalt<span>o</span></a>
    <nav aria-label="{{ $labels['language'] }}">
        <ul class="sprachen">
            @foreach ($languages as $language)
                <li><a href="{{ $language['url'] }}" hreflang="{{ $language['code'] }}" lang="{{ $language['code'] }}" @if ($language['current']) aria-current="page" @endif>{{ $language['label'] }}</a></li>
            @endforeach
        </ul>
    </nav>
</header>
<main class="raster">
    <nav class="texte" aria-label="{{ $labels['texts'] }}">
        <h2>{{ $labels['texts'] }}</h2>
        <ul>
            @foreach ($texts as $text)
                <li><a href="{{ $text['url'] }}" @if ($text['slug'] === $page['slug']) aria-current="page" @endif>{{ $text['title'] }}</a></li>
            @endforeach
        </ul>
    </nav>
    <article>
        <h1>{{ $page['title'] }}</h1>
        {{-- Markdown aus dem Repository, auf dem Server ohne rohes HTML und ohne unsichere Links gerendert --}}
        {!! $page['html'] !!}
    </article>
</main>
</body>
</html>
