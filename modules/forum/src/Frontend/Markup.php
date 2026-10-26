<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityRank;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Kleine Bausteine des Forum-Markups: Profilbild, Zeitangabe, Zahl, Seitenwahl. Klassen mit Präfix `forum-`, damit
 * jedes Theme sie gestalten kann; die Grundgestalt liefert `Styles`.
 */
final class Markup
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES);
    }

    /**
     * Profilbild oder Initialen im Sechseck der Marke (TB 47) — dekorativ, der Name steht daneben. Grössen: `klein`
     * (28 px), ohne (40 px), `gross` (56 px), `profil` (112 px).
     */
    public static function avatar(?CommunityProfile $profile, string $fallbackName, string $size = ''): string
    {
        $class = 'forum-avatar'.($size !== '' ? ' forum-avatar--'.$size : '');
        $url = $profile?->avatarUrl();
        $pixels = ['klein' => 28, 'gross' => 56, 'profil' => 112][$size] ?? 40;
        if ($url !== null) {
            return sprintf('<img class="%s" src="%s" alt="" loading="lazy" width="%d" height="%3$d">', $class, self::e($url), $pixels);
        }
        $initials = $profile?->initials() ?? mb_strtoupper(mb_substr(trim($fallbackName), 0, 1) ?: '?');

        return sprintf('<span class="%s forum-avatar--f%d" aria-hidden="true">%s</span>', $class, self::tone($profile, $fallbackName), self::e($initials));
    }

    /** Eine von sechs Farben, fest je Name — so unterscheiden sich Initialen in Listen und Waben im Kompass. */
    public static function tone(?CommunityProfile $profile, string $fallbackName): int
    {
        return 1 + crc32($profile->name ?? $fallbackName) % 6;
    }

    /**
     * Mehrere Profilbilder übereinander gelegt (Beteiligte, wer online ist), mit «+n», wenn es mehr sind.
     *
     * @param  list<string>  $avatars  fertiges Markup aus {@see avatar()}
     */
    public static function stack(array $avatars, int $more = 0, string $label = ''): string
    {
        $items = implode('', array_map(static fn (string $a): string => '<span class="forum-stapel__bild">'.$a.'</span>', $avatars));
        if ($more > 0) {
            $items .= sprintf('<span class="forum-stapel__bild"><span class="forum-avatar forum-avatar--klein forum-avatar--mehr" aria-hidden="true">+%d</span></span>', $more);
        }

        return sprintf('<span class="forum-stapel"%s>%s</span>', $label === '' ? ' aria-hidden="true"' : ' role="img" aria-label="'.self::e($label).'"', $items);
    }

    public static function time(?DateTimeInterface $time, string $lang): string
    {
        if ($time === null) {
            return '';
        }
        $time = Carbon::instance($time);
        $local = $time->copy();
        $local->locale($lang);
        $minutes = (int) $time->diffInMinutes(now(), true);
        $label = $time->isPast() && $minutes < 60 ? trans_choice('vor :count Minute|vor :count Minuten', max(1, $minutes), ['count' => max(1, $minutes)], $lang)
            : ($time->isPast() && $minutes < 360 ? trans_choice('vor :count Stunde|vor :count Stunden', intdiv($minutes, 60), ['count' => intdiv($minutes, 60)], $lang)
            : ($time->isToday() ? __('heute, :zeit', ['zeit' => $local->isoFormat('HH:mm')], $lang)
            : ($time->isYesterday() ? __('gestern, :zeit', ['zeit' => $local->isoFormat('HH:mm')], $lang) : $local->isoFormat(self::dayFormat($lang, false)))));

        return sprintf('<time datetime="%s" title="%s">%s</time>', self::e($time->toIso8601String()), self::e($local->isoFormat('LLLL')), self::e($label));
    }

    /** Ein Datum mit Uhrzeit als Text (für Sperren: «bis 30. Okt. 2026, 14:00»). */
    public static function date(DateTimeInterface $time, string $lang): string
    {
        $local = Carbon::instance($time)->copy();
        $local->locale($lang);

        return $local->isoFormat(self::dayFormat($lang, true).', HH:mm');
    }

    /**
     * Muster für einen Tag: deutsch «5. Oktober 2026» bzw. «5. Okt. 2026», sonst die Form der Sprache
     * («5 octobre 2026», «October 5, 2026»).
     */
    public static function dayFormat(string $lang, bool $short): string
    {
        if ($lang === 'de') {
            return $short ? 'D. MMM YYYY' : 'D. MMMM YYYY';
        }

        return $short ? 'll' : 'LL';
    }

    /**
     * Symbole der Foren, Leisten und Werkzeuge (TB 42, Neugestaltung TB 47) — Linienzeichnungen im Stil von Lucide,
     * 24 × 24, 2 px Strich, Farbe vom Text.
     */
    public const ICONS = [
        'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'megaphone' => '<path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15 9a3 3 0 0 1 0 6"/><path d="M18 6a7 7 0 0 1 0 12"/>',
        'question' => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'lightbulb' => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z"/>',
        'bug' => '<rect x="8" y="6" width="8" height="14" rx="4"/><path d="M19 7l-3 2M5 7l3 2M19 19l-3-2M5 19l3-2M20 13h-4M4 13h4M10 4l1 2M14 4l-1 2"/>',
        'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'unlock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.7-1.5"/>',
        'wand' => '<path d="M15 4V2M15 16v-2M8 9h2M20 9h2M17.8 11.8 19 13M17.8 6.2 19 5M3 21l9-9M12.2 6.2 11 5"/>',
        'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4z"/>',
        'hash' => '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>',
        'pencil' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5v14z"/><path d="M6.5 17A2.5 2.5 0 0 0 4 19.5 2.5 2.5 0 0 0 6.5 22H20v-5"/>',
        'star' => '<path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'link' => '<path d="M10 14 21 3M15 3h6v6M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'dots' => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
        'sort' => '<path d="M3 6h10M3 12h7M3 18h4M17 4v16M14 17l3 3 3-3"/>',
        'filter' => '<path d="M3 4h18l-7 8v6l-4 2v-8z"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'reply' => '<path d="M9 17l-5-5 5-5"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
        'quote' => '<path d="M3 21c3 0 7-1 7-8V5H3v7h4c0 3-1 5-4 5z"/><path d="M14 21c3 0 7-1 7-8V5h-7v7h4c0 3-1 5-4 5z"/>',
        'bookmark' => '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
        'bell-off' => '<path d="M8.7 3A6 6 0 0 1 18 8a21 21 0 0 0 .6 5M17 17H3s3-2 3-9a4.7 4.7 0 0 1 .3-2"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0M2 2l20 20"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
        'pin' => '<path d="M12 17v5"/><path d="M9 10.8V4h6v6.8l2.5 3.2H6.5z"/>',
        'move' => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/><path d="M3 5v14"/>',
        'copy' => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
        'merge' => '<circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M6 21V9a9 9 0 0 0 9 9"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>',
        'restore' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M9.9 4.2A10 10 0 0 1 12 4c6 0 10 8 10 8a17 17 0 0 1-2.2 3.2M6.6 6.6A17 17 0 0 0 2 12s4 8 10 8a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2M2 2l20 20"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
        'flame' => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.4-.5-2-1-3-1-2.1-.2-4 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.2.4-2.3 1-3.2.3 1.4 1.2 2.7 2.5 2.7z"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'smile' => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01M15 9h.01"/>',
        'award' => '<circle cx="12" cy="8" r="6"/><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"/>',
        'alert' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
        'home' => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'zap' => '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
        'compass' => '<circle cx="12" cy="12" r="10"/><path d="m16.2 7.8-2.1 6.3-6.3 2.1 2.1-6.3z"/>',
        'up' => '<path d="m18 15-6-6-6 6"/>',
        'down' => '<path d="m6 9 6 6 6-6"/>',
        'arrow-down' => '<path d="M12 5v14M19 12l-7 7-7-7"/>',
        'arrow-up' => '<path d="M12 19V5M5 12l7-7 7 7"/>',
        'list-check' => '<path d="M11 6h10M11 12h10M11 18h10"/><path d="m3 6 1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17"/>',
        'select' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="m9 12 2 2 4-4"/>',
        'ban' => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
        'rss' => '<path d="M4 11a9 9 0 0 1 9 9M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5M15 12H3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
        'keyboard' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M6 9h.01M10 9h.01M14 9h.01M18 9h.01M6 13h.01M18 13h.01M10 13h4M7 16h10"/>',
        'sparkles' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
        'cake' => '<path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8M2 21h20M4 16s1.5-1 4-1 4 2 6 2 4-1 4-1M12 8v3M12 4l.01.01"/>',
    ];

    public static function icon(string $name, string $class = 'forum-icon'): string
    {
        return sprintf('<svg class="%s" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            self::e($class), self::ICONS[$name] ?? self::ICONS['chat']);
    }

    /** Ein kleines Symbol (16 px) für Knöpfe, Chips und Zeilen. */
    public static function mini(string $name): string
    {
        return self::icon($name, 'forum-icon forum-icon--klein');
    }

    /** Kurze Zahl wie bei WoltLab: 677, 1,3k, 57k, 1,2 Mio. */
    public static function compact(int $value, string $lang): string
    {
        $sep = $lang === 'en' ? '.' : ',';
        if ($value < 1000) {
            return (string) $value;
        }
        if ($value < 1_000_000) {
            $k = $value / 1000;

            return ($k < 10 ? str_replace('.', $sep, (string) round($k, 1)) : (string) (int) round($k)).'k';
        }

        return str_replace('.', $sep, (string) round($value / 1_000_000, 1)).' '.($lang === 'en' ? 'M' : 'Mio.');
    }

    /** Sprache eines Themas als Fähnchen (Emoji) mit Namen für Screenreader. */
    public static function flag(string $locale, string $lang): string
    {
        $flags = ['de' => '🇩🇪', 'en' => '🇬🇧', 'fr' => '🇫🇷', 'it' => '🇮🇹'];
        $names = ['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'it' => 'Italiano'];

        return sprintf('<span class="forum-sprache" title="%s" aria-label="%1$s">%s</span>', self::e($names[$locale] ?? strtoupper($locale)), $flags[$locale] ?? self::e(strtoupper($locale)));
    }

    /** Eine Zahl mit Tausendertrennung, fertig für HTML. */
    public static function number(int $value, string $lang): string
    {
        return self::e(self::digits($value, $lang));
    }

    /** Eine Zahl mit Tausendertrennung als Text — für Übersetzungen, die danach als Ganzes maskiert werden. */
    public static function digits(int $value, string $lang): string
    {
        return number_format($value, 0, '.', $lang === 'en' ? ',' : '\'');
    }

    /**
     * Seitenwahl mit `?seite=`; ohne Folgeseiten nichts.
     *
     * @param  callable(int): string  $url
     */
    public static function pagination(int $page, int $pages, callable $url, string $lang): string
    {
        if ($pages <= 1) {
            return '';
        }
        $items = '';
        $shown = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn (int $p): bool => $p >= 1 && $p <= $pages));
        sort($shown);
        $last = 0;
        foreach ($shown as $p) {
            if ($p > $last + 1) {
                $items .= '<li class="forum-pages__gap" aria-hidden="true">…</li>';
            }
            $items .= $p === $page
                ? sprintf('<li><span aria-current="page">%d</span></li>', $p)
                : sprintf('<li><a href="%s">%d</a></li>', self::e($url($p)), $p);
            $last = $p;
        }

        return sprintf('<nav class="forum-pages" aria-label="%s"><ul>%s</ul></nav>', self::e(__('Seiten', [], $lang)), $items);
    }

    /** Ein Rang als Chip — mit Zeichen (TB 52) statt des farbigen Punkts, wenn gesetzt. */
    public static function rank(CommunityRank $rank): string
    {
        $icon = is_string($rank->icon) && $rank->icon !== '' ? '<span class="forum-rang__zeichen" aria-hidden="true">'.self::e($rank->icon).'</span>' : '';

        return sprintf('<span class="forum-rang forum-rang--%s%s">%s%s</span>', self::e($rank->color), $icon === '' ? '' : ' hat-zeichen', $icon, self::e($rank->title));
    }
}
