<?php

declare(strict_types=1);

namespace Basalto\Core\Demo;

/**
 * Schnellzugang im Frontend der Demo (TB 16 Nachtrag): «Anmelden» öffnet ein Fenster mit den drei Rollen statt der
 * Anmeldung für Mitglieder — deren Code käme in der Demo nie an (Mails abgefangen). Ein HTML-Popover, ohne Skript, damit
 * auch Themes ohne JavaScript es tragen; die Farben kommen aus den Design-Tokens des Themes.
 */
final class DemoAccessMarkup
{
    public const ID = 'demo-zugang';

    public static function render(string $lang): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $labels = [
            'betreiber' => [__('Als Betreiber ansehen', [], $lang), __('Alles: Einrichtung, Themes, Benutzer, Einstellungen', [], $lang)],
            'redaktion' => [__('Als Redaktion ansehen', [], $lang), __('Seiten, Blog und Medien bearbeiten', [], $lang)],
            'uebersetzung' => [__('Als Übersetzung ansehen', [], $lang), __('Inhalte aus der Quellfassung übersetzen', [], $lang)],
        ];
        $items = '';
        foreach (DemoMode::ROLES as $role) {
            $items .= sprintf('<li><a class="demo-access-role%s" href="%s">%s</a><span>%s</span></li>', $role === 'betreiber' ? ' is-primary' : '',
                $e(route('basalto.login.demo', ['role' => $role], false)), $e($labels[$role][0]), $e($labels[$role][1]));
        }

        return sprintf('<button type="button" class="members-link demo-access-open" popovertarget="%1$s">%2$s</button>'
            .'<div id="%1$s" popover class="demo-access" role="dialog" aria-labelledby="%1$s-titel">'
            .'<p class="demo-access-title" id="%1$s-titel">%3$s</p><p class="demo-access-lead">%4$s</p><ul>%5$s</ul>'
            .'<button type="button" class="demo-access-close" popovertarget="%1$s" popovertargetaction="hide">%6$s</button></div>%7$s',
            self::ID, $e(__('Anmelden', [], $lang)), $e(__('Schnellzugang zur Demo', [], $lang)), $e(__('Ohne Passwort und ohne Code — nur in der Demo.', [], $lang)),
            $items, $e(__('Schliessen', [], $lang)), self::STYLE);
    }

    private const STYLE = '<style>.demo-access{max-width:24rem;width:calc(100% - 2rem);padding:1.25rem;border:1px solid var(--color-border,#80868c);border-radius:12px;'
        .'background:var(--color-surface,#fff);color:var(--color-text,#23262b)}.demo-access::backdrop{background:rgb(0 0 0 / .35)}'
        .'.demo-access-title{font-weight:700;font-size:1.1rem;margin:0 0 .25rem}.demo-access-lead{margin:0 0 1rem;color:var(--color-muted,#3b3f46);font-size:.9rem}'
        .'.demo-access ul{list-style:none;margin:0;padding:0;display:grid;gap:.75rem}.demo-access li span{display:block;font-size:.85rem;color:var(--color-muted,#3b3f46);margin-top:.25rem}'
        .'.demo-access-role{display:block;text-align:center;padding:.65rem 1rem;border-radius:8px;border:1px solid var(--color-accent,#b53f1d);color:var(--color-accent,#b53f1d);font-weight:600;text-decoration:none}'
        .'.demo-access-role.is-primary{background:var(--color-accent,#b53f1d);color:var(--color-accent-text,#fff)}.demo-access-role:focus-visible,.demo-access-close:focus-visible{outline:3px solid var(--color-focus,#e4572e);outline-offset:2px}'
        .'.demo-access-close{margin-top:1rem;background:none;border:0;color:var(--color-link,#b53f1d);text-decoration:underline;cursor:pointer;font:inherit}</style>';
}
