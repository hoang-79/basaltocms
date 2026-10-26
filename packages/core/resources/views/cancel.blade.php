{{-- «Verträge hier kündigen» (CR-011 TB 59, § 312k BGB): eigenständig, ohne Skript, Anmeldung und Fremdressource; Sprache setzt der Controller --}}
@php
    $md = static fn (string $text): string => (string) \Illuminate\Support\Str::inlineMarkdown(e($text));
    $error = static fn (string $field): ?string => isset($errors) && $errors->has($field) ? $errors->first($field) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $view === 'form' ? __('Verträge hier kündigen') : ($view === 'receipt' ? __('Ihre Kündigung ist eingegangen') : __('Kündigung zurücknehmen')) }} · {{ $site->name }}</title>
    <style>
        :root { --basalt: #23262b; --basalt-light: #3b3f46; --lava: #e4572e; --lava-dark: #b53f1d; --ash-dark: #62676e; --ash-light: #e3e6ea; --paper: #f6f4f0; --error: #b42318; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--paper); color: var(--basalt); font: 16px/1.6 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif; }
        a { color: inherit; }
        header, main, footer { max-width: 44rem; margin: 0 auto; padding: 0 20px; }
        header { display: flex; flex-wrap: wrap; gap: 8px 20px; align-items: baseline; justify-content: space-between; padding-top: 22px; padding-bottom: 12px; }
        .marke { font-weight: 700; font-size: 18px; text-decoration: none; }
        .sprachen { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 14px; list-style: none; margin: 0; padding: 0; text-transform: uppercase; }
        .sprachen [aria-current] { font-weight: 700; text-decoration: none; }
        article { background: #fff; border: 1px solid var(--ash-light); border-top: 3px solid var(--lava); border-radius: 10px; padding: 24px 26px; overflow-wrap: break-word; }
        h1 { font-size: 28px; line-height: 1.2; margin: 0 0 10px; }
        .lead { color: var(--basalt-light); margin: 0 0 18px; }
        fieldset { border: 0; border-top: 1px solid var(--ash-light); margin: 0; padding: 16px 0 6px; min-width: 0; }
        legend { font-weight: 700; padding: 0; margin-bottom: 8px; float: left; width: 100%; }
        legend + * { clear: both; }
        .wahl { display: grid; gap: 8px; margin-bottom: 12px; }
        .wahl label { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--ash-light); border-radius: 8px; cursor: pointer; }
        .wahl label:has(input:checked) { border-color: var(--lava); box-shadow: inset 3px 0 0 var(--lava); }
        .wahl input { margin-top: 5px; accent-color: var(--lava-dark); }
        .wahl small, .hilfe { display: block; color: var(--ash-dark); font-size: 14px; }
        .feld { margin-bottom: 14px; }
        .feld > label { display: block; font-weight: 600; margin-bottom: 4px; }
        input[type=text], input[type=email], input[type=date], textarea { width: 100%; min-height: 44px; padding: 9px 12px; font: inherit; color: inherit; border: 1px solid #b9bec5; border-radius: 8px; background: #fff; }
        textarea { min-height: 96px; resize: vertical; }
        input:focus-visible, textarea:focus-visible, button:focus-visible, a:focus-visible, .wahl input:focus-visible { outline: 3px solid var(--lava); outline-offset: 2px; }
        [aria-invalid=true] { border-color: var(--error); }
        .fehler { color: var(--error); font-size: 14px; margin: 4px 0 0; }
        .meldung { border: 1px solid var(--error); background: #fef3f2; color: var(--error); border-radius: 8px; padding: 10px 14px; margin: 0 0 16px; }
        button { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 22px; border: 0; border-radius: 8px; background: var(--lava-dark); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        button:hover { background: #9a3418; }
        .knopf-zeile { padding-top: 16px; border-top: 1px solid var(--ash-light); }
        .klein { font-size: 14px; color: var(--ash-dark); }
        .honig { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        .ergebnis { background: #fdf0eb; border-left: 3px solid var(--lava); padding: 10px 14px; border-radius: 6px; margin: 0 0 16px; }
        .stempel { font-weight: 600; margin: 0 0 14px; }
        dl { display: grid; grid-template-columns: minmax(0, 1fr); gap: 2px 16px; margin: 0 0 16px; }
        @media (min-width: 36rem) { dl { grid-template-columns: 12rem minmax(0, 1fr); } }
        dt { color: var(--ash-dark); font-size: 14px; }
        dd { margin: 0 0 6px; }
        footer { padding-top: 18px; padding-bottom: 32px; font-size: 13px; color: var(--ash-dark); text-align: center; }
        footer p { margin: 4px 0; }
        @media (max-width: 40rem) { article { padding: 18px 16px; } h1 { font-size: 24px; } }
    </style>
</head>
<body>
<header>
    <a class="marke" href="/">{{ $site->name }}</a>
    @if ($languages !== [])
        <nav aria-label="{{ __('Sprache') }}">
            <ul class="sprachen">
                @foreach ($languages as $language)
                    <li><a href="{{ $language['url'] }}" hreflang="{{ $language['code'] }}" lang="{{ $language['code'] }}" @if ($language['current']) aria-current="page" @endif>{{ $language['code'] }}</a></li>
                @endforeach
            </ul>
        </nav>
    @endif
</header>
<main>
    <article>
        @if ($view === 'form')
            <h1>{{ __('Verträge hier kündigen') }}</h1>
            <p class="lead">{{ __('Hier kündigen Sie einen Vertrag mit :vendor — ohne Anmeldung. Die Bestätigung mit Datum und Uhrzeit des Eingangs sehen Sie sofort, und sie kommt per E-Mail.', ['vendor' => $vendor['name']]) }}</p>
            @if ($error('form'))
                <p class="meldung" role="alert">{{ $error('form') }}</p>
            @elseif ($errors->any())
                <p class="meldung" role="alert">{{ __('Bitte prüfen Sie die markierten Angaben.') }}</p>
            @endif
            <form method="post" action="{{ $action }}">
                <input type="hidden" name="_sprache" value="{{ $lang }}">
                <input type="hidden" name="{{ $spam['token'] }}" value="{{ $spam['value'] }}">
                <div class="honig" aria-hidden="true"><label for="kuendigen-website">Website</label><input type="text" id="kuendigen-website" name="{{ $spam['honeypot'] }}" tabindex="-1" autocomplete="off"></div>

                <fieldset>
                    <legend>{{ __('Art der Kündigung') }}</legend>
                    <div class="wahl">
                        <label><input type="radio" name="kind" value="ordinary" @checked(($old['kind'] ?? '') === 'ordinary')> <span>{{ __('Ordentliche Kündigung') }}<small>{{ __('zum nächstmöglichen Zeitpunkt oder zu Ihrem Wunschdatum') }}</small></span></label>
                        <label><input type="radio" name="kind" value="extraordinary" @checked(($old['kind'] ?? '') === 'extraordinary')> <span>{{ __('Ausserordentliche Kündigung') }}<small>{{ __('fristlos aus wichtigem Grund') }}</small></span></label>
                    </div>
                    <div class="feld">
                        <label for="kuendigen-grund">{{ __('Grund (nur bei ausserordentlicher Kündigung)') }}</label>
                        <textarea id="kuendigen-grund" name="reason" maxlength="2000" @if ($error('reason')) aria-invalid="true" aria-describedby="kuendigen-grund-fehler" @endif>{{ $old['reason'] ?? '' }}</textarea>
                        @if ($error('reason'))<p class="fehler" id="kuendigen-grund-fehler">{{ $error('reason') }}</p>@endif
                    </div>
                </fieldset>

                <fieldset>
                    <legend>{{ __('Wer kündigt?') }}</legend>
                    <div class="feld">
                        <label for="kuendigen-name">{{ __('Vor- und Nachname') }}</label>
                        <input type="text" id="kuendigen-name" name="name" value="{{ $old['name'] ?? '' }}" required maxlength="160" autocomplete="name" @if ($error('name')) aria-invalid="true" aria-describedby="kuendigen-name-fehler" @endif>
                        @if ($error('name'))<p class="fehler" id="kuendigen-name-fehler">{{ $error('name') }}</p>@endif
                    </div>
                    <div class="feld">
                        <label for="kuendigen-email">{{ __('E-Mail-Adresse') }}</label>
                        <input type="email" id="kuendigen-email" name="email" value="{{ $old['email'] ?? '' }}" required maxlength="254" autocomplete="email" aria-describedby="kuendigen-email-hilfe{{ $error('email') ? ' kuendigen-email-fehler' : '' }}" @if ($error('email')) aria-invalid="true" @endif>
                        <span class="hilfe" id="kuendigen-email-hilfe">{{ __('Die Adresse, mit der Sie den Vertrag abgeschlossen haben — daran erkennen wir ihn, und dorthin geht die Bestätigung.') }}</span>
                        @if ($error('email'))<p class="fehler" id="kuendigen-email-fehler">{{ $error('email') }}</p>@endif
                    </div>
                </fieldset>

                <fieldset>
                    <legend>{{ __('Welcher Vertrag?') }}</legend>
                    <div class="wahl">
                        @foreach ($contracts as $contract)
                            <label><input type="radio" name="contract" value="{{ $contract['key'] }}" @checked(($old['contract'] ?? '') === $contract['key']) required> <span>{{ $contract['label'] }}<small>{{ __('Kennung:') }} {{ $contract['hint'] }}</small></span></label>
                        @endforeach
                    </div>
                    @if ($error('contract'))<p class="fehler">{{ $error('contract') }}</p>@endif
                    <div class="feld">
                        <label for="kuendigen-kennung">{{ __('Kennung des Vertrags (falls bekannt)') }}</label>
                        <input type="text" id="kuendigen-kennung" name="reference" value="{{ $old['reference'] ?? '' }}" maxlength="200" aria-describedby="kuendigen-kennung-hilfe{{ $error('reference') ? ' kuendigen-kennung-fehler' : '' }}" @if ($error('reference')) aria-invalid="true" @endif>
                        <span class="hilfe" id="kuendigen-kennung-hilfe">{{ __('Haben Sie mehrere Verträge, nennen Sie den, den Sie kündigen.') }}</span>
                        @if ($error('reference'))<p class="fehler" id="kuendigen-kennung-fehler">{{ $error('reference') }}</p>@endif
                    </div>
                </fieldset>

                <fieldset>
                    <legend>{{ __('Zu welchem Zeitpunkt?') }}</legend>
                    <div class="wahl">
                        <label><input type="radio" name="when" value="next" @checked(($old['when'] ?? '') === 'next')> <span>{{ __('Zum nächstmöglichen Zeitpunkt') }}</span></label>
                        <label><input type="radio" name="when" value="date" @checked(($old['when'] ?? '') === 'date')> <span>{{ __('Zu einem bestimmten Datum') }}<small>{{ __('Liegt es vor dem nächstmöglichen Ende, gilt dieses.') }}</small></span></label>
                    </div>
                    <div class="feld">
                        <label for="kuendigen-datum">{{ __('Datum') }}</label>
                        <input type="date" id="kuendigen-datum" name="wish_date" value="{{ $old['wish_date'] ?? '' }}" min="{{ $today }}" @if ($error('wish_date')) aria-invalid="true" aria-describedby="kuendigen-datum-fehler" @endif>
                        @if ($error('wish_date'))<p class="fehler" id="kuendigen-datum-fehler">{{ $error('wish_date') }}</p>@endif
                    </div>
                </fieldset>

                <div class="knopf-zeile">
                    <button type="submit">{{ __('Jetzt kündigen') }}</button>
                    <p class="klein">{{ __('Ihre Angaben verwenden wir nur, um die Kündigung auszuführen und zu belegen.') }}</p>
                </div>
            </form>
        @else
            @php $item = $item ?? []; @endphp
            @if ($view === 'receipt')
                <h1>{{ __('Ihre Kündigung ist eingegangen') }}</h1>
            @else
                <h1>{{ __('Kündigung zurücknehmen') }}</h1>
            @endif
            <p class="stempel">{{ __('Eingang am :date um :time Uhr · Nummer :number', ['date' => $item['date'], 'time' => $item['time'], 'number' => $item['number']]) }}</p>
            @if ($view === 'revoke' && $done === false)
                <p class="meldung" role="alert">{{ __('Die Kündigung lässt sich nicht mehr zurücknehmen — der Vertrag ist schon zu Ende oder die Kündigung bereits zurückgenommen.') }}</p>
            @endif
            <p class="ergebnis">{!! $md($item['outcome']) !!}</p>
            @foreach ($item['notes'] as $note)
                <p>{{ $note }}</p>
            @endforeach
            <dl>
                <dt>{{ __('Art der Kündigung') }}</dt><dd>{{ $item['kind'] === 'extraordinary' ? __('Ausserordentliche Kündigung') : __('Ordentliche Kündigung') }}</dd>
                @if ($item['reason'])<dt>{{ __('Grund') }}</dt><dd>{{ $item['reason'] }}</dd>@endif
                <dt>{{ __('Name') }}</dt><dd>{{ $item['name'] }}</dd>
                <dt>{{ __('E-Mail-Adresse') }}</dt><dd>{{ $item['email'] }}</dd>
                <dt>{{ __('Vertrag') }}</dt><dd>{{ $item['contract'] }}</dd>
                @if ($item['reference'])<dt>{{ __('Kennung') }}</dt><dd>{{ $item['reference'] }}</dd>@endif
                <dt>{{ __('Gewünschtes Ende') }}</dt><dd>{{ $item['wish'] ?? __('zum nächstmöglichen Zeitpunkt') }}</dd>
            </dl>
            @if ($view === 'receipt')
                <p class="klein">{{ __('Die Bestätigung ist auch per E-Mail an :email unterwegs. Speichern oder drucken Sie diese Seite als Beleg.', ['email' => $item['email']]) }}</p>
            @elseif ($action)
                <form method="post" action="{{ $action }}" class="knopf-zeile">
                    <button type="submit">{{ __('Kündigung zurücknehmen') }}</button>
                    <p class="klein">{{ __('Der Vertrag läuft dann weiter wie bisher.') }}</p>
                </form>
            @endif
        @endif
    </article>
</main>
<footer>
    @if ($vendor['lines'] !== [])
        <p>{{ implode(' · ', $vendor['lines']) }}</p>
    @endif
    <p>
        @foreach ($vendor['links'] as $link)
            <a href="{{ $link['url'] }}">{{ $link['label'] }}</a> ·
        @endforeach
        <a href="{{ $withdrawal }}">{{ __('Widerrufsbelehrung') }}</a>
    </p>
</footer>
</body>
</html>
