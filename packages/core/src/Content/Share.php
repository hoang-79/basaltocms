<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

/**
 * Teilen (TB 51, CR-009 Nr. 1): Knöpfe für E-Mail und soziale Netzwerke als gewöhnliche Links — keine fremden
 * Skripte, Bilder oder Zähler. Erst der Klick öffnet das Netzwerk; vorher erfährt es nichts. Dazu «Link kopieren» und
 * auf Geräten, die es können, das Teilen des Systems (beide erst mit Skript sichtbar). Für Lava als Tag
 * `{% share %}` (Adresse und Titel der Seite, auf Wunsch `title:` und `url:`), im Forum am Thema und am Beitrag.
 */
final class Share
{
    /** Ziele in dieser Reihenfolge: Schlüssel → [Name, Adresse mit {url} und {title}]. */
    public const TARGETS = [
        'mail' => ['E-Mail', 'mailto:?subject={title}&body={url}'],
        'whatsapp' => ['WhatsApp', 'https://wa.me/?text={title}%20{url}'],
        'telegram' => ['Telegram', 'https://t.me/share/url?url={url}&text={title}'],
        'x' => ['X', 'https://x.com/intent/post?text={title}&url={url}'],
        'bluesky' => ['Bluesky', 'https://bsky.app/intent/compose?text={title}%20{url}'],
        'facebook' => ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u={url}'],
        'linkedin' => ['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url={url}'],
    ];

    /**
     * Die Links zum Teilen.
     *
     * @return list<array{key: string, label: string, href: string}>
     */
    public static function links(string $url, string $title): array
    {
        $links = [];
        foreach (self::TARGETS as $key => [$label, $pattern]) {
            $links[] = ['key' => $key, 'label' => $label, 'href' => strtr($pattern, ['{url}' => rawurlencode($url), '{title}' => rawurlencode($title)])];
        }

        return $links;
    }

    /** Die Leiste: Überschrift, System-Teilen und «Link kopieren» (mit Skript), die Netzwerke als Links, ein Satz zum Datenschutz. */
    public static function html(string $url, string $title, string $lang): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $t = static fn (string $text): string => (string) __($text, [], $lang);
        $items = sprintf('<li hidden><button type="button" class="basalto-teilen__knopf basalto-teilen__knopf--system" data-teilen="system">%s %s</button></li>', self::icon('share'), $e($t('Teilen …')))
            .sprintf('<li hidden><button type="button" class="basalto-teilen__knopf" data-teilen="kopieren" data-fertig="%s">%s <span>%s</span></button></li>', $e($t('Link kopiert')), self::icon('link'), $e($t('Link kopieren')));
        foreach (self::links($url, $title) as $link) {
            $items .= sprintf('<li><a class="basalto-teilen__knopf basalto-teilen__knopf--%s" href="%s"%s>%s%s</a></li>', $e($link['key']), $e($link['href']),
                $link['key'] === 'mail' ? '' : ' target="_blank" rel="noopener nofollow"', $link['key'] === 'mail' ? self::icon('mail').' ' : '', $e($link['label']));
        }

        return sprintf('<div class="basalto-teilen" data-basalto-teilen data-url="%s" data-titel="%s"><p class="basalto-teilen__titel">%s</p><ul>%s</ul><p class="basalto-teilen__hinweis">%s</p></div>%s',
            $e($url), $e($title), $e($t('Teilen')), $items, $e($t('Erst ein Klick öffnet das Netzwerk — vorher geht nichts dorthin.')), self::assets());
    }

    private static function icon(string $name): string
    {
        $paths = [
            'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
            'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        ];

        return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$paths[$name].'</svg>';
    }

    /** Stil und Skript einmal je Anfrage, aus den Farben des Themes. */
    private static function assets(): string
    {
        $request = request();
        if ($request->attributes->get('basalto.teilen.assets') === true) {
            return '';
        }
        $request->attributes->set('basalto.teilen.assets', true);

        return '<style>.basalto-teilen{display:grid;gap:8px;margin:1.2em 0}.basalto-teilen__titel{margin:0;font-size:.8em;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--color-muted,#3b3f46)}'
            .'.basalto-teilen ul{display:flex;flex-wrap:wrap;gap:6px;margin:0;padding:0;list-style:none}.basalto-teilen li[hidden]{display:none}'
            .'.basalto-teilen .basalto-teilen__knopf{display:inline-flex;align-items:center;gap:6px;min-height:36px;margin:0;padding:0 12px;border:1px solid var(--color-line,#e3e6ea);border-radius:999px;background:var(--color-surface,#fff);color:var(--color-text,#23262b);font:inherit;font-size:.88em;font-weight:600;text-decoration:none;cursor:pointer}'
            .'.basalto-teilen .basalto-teilen__knopf:hover{border-color:var(--color-accent,#b53f1d);color:var(--color-text,#23262b)}.basalto-teilen .basalto-teilen__knopf:focus-visible{outline:2px solid var(--color-focus,#b53f1d);outline-offset:2px}'
            .'.basalto-teilen .basalto-teilen__knopf--system{border-color:var(--color-accent,#b53f1d);background:var(--color-accent,#b53f1d);color:var(--color-accent-text,#fff)}'
            .'.basalto-teilen__hinweis{margin:0;font-size:.78em;color:var(--color-muted,#3b3f46)}</style>'
            .'<script>document.querySelectorAll("[data-basalto-teilen]").forEach(function(box){if(box.dataset.bereit){return}box.dataset.bereit="1";var url=box.dataset.url,title=box.dataset.titel;'
            .'var sys=box.querySelector("[data-teilen=system]");if(sys&&navigator.share){sys.parentNode.hidden=false;sys.addEventListener("click",function(){navigator.share({title:title,url:url}).catch(function(){})})}'
            .'var copy=box.querySelector("[data-teilen=kopieren]");if(copy&&navigator.clipboard){copy.parentNode.hidden=false;copy.addEventListener("click",function(){navigator.clipboard.writeText(url).then(function(){var s=copy.querySelector("span");var before=s.textContent;s.textContent=copy.dataset.fertig;setTimeout(function(){s.textContent=before},1800)})})}})</script>';
    }
}
