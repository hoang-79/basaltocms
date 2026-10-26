<?php

declare(strict_types=1);

namespace Basalto\Core\Seo;

use Basalto\Core\Content\Pages;
use Basalto\Core\Models\NotFoundHit;
use Basalto\Core\Models\Redirect;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;

/**
 * Regeln für Umleitungen von Hand und aus dem Import (M-10): Quelle als Pfad (volle Adresse der Site wird gekürzt),
 * Ziel als Pfad oder http(s)-Adresse, 301/302 leiten weiter, 410 meldet «gibt es nicht mehr» ohne Ziel. Eine Umleitung
 * von einer veröffentlichten Seite würde nie greifen, eine auf sich selbst oder im Kreis nie enden — beides wird abgewiesen.
 * Wer eine Umleitung für einen Pfad aus dem 404-Protokoll anlegt, erledigt damit dessen Zeile.
 */
final class RedirectRules
{
    public const STATUSES = [301, 302, 410];

    public function __construct(private readonly Pages $pages) {}

    /**
     * @return array{from: string, to: string, status: int, locale: string}|array{error: string, field: string}
     */
    public function check(Site $site, string $from, string $to, int $status, ?int $exceptId = null): array
    {
        if (! in_array($status, self::STATUSES, true)) {
            return ['error' => __('Status 301, 302 oder 410.'), 'field' => 'status'];
        }
        $from = self::path($from);
        if ($from === null || $from === '/') {
            return ['error' => __('Die alte Adresse ist ein Pfad wie «/alt/seite».'), 'field' => 'from'];
        }
        [$locale, $path] = $this->pages->splitLocale($site, $from);
        if ($this->pages->resolve($site, $locale, $path) !== null) {
            return ['error' => __('Unter :path steht eine veröffentlichte Seite — die Umleitung würde nie greifen.', ['path' => $from]), 'field' => 'from'];
        }
        $to = trim($to);
        if ($status === 410) {
            $to = '';
        } elseif (preg_match('#^https?://#i', $to) === 1) {
            if (filter_var($to, FILTER_VALIDATE_URL) === false) {
                return ['error' => __('Das Ziel ist keine gültige Adresse.'), 'field' => 'to'];
            }
        } else {
            $to = self::path($to) ?? '';
            if ($to === '') {
                return ['error' => __('Ziel als Pfad («/neu») oder Adresse («https://…») — oder Status 410.'), 'field' => 'to'];
            }
            if ($to === $from) {
                return ['error' => __('Die Umleitung zeigt auf sich selbst.'), 'field' => 'to'];
            }
            $back = Redirect::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_path', $to)
                ->when($exceptId !== null, static fn ($q) => $q->whereKeyNot($exceptId))->value('to_path');
            if ($back === $from) {
                return ['error' => __(':to leitet schon auf :from — das wäre ein Kreis.', ['to' => $to, 'from' => $from]), 'field' => 'to'];
            }
        }

        return ['from' => $from, 'to' => $to, 'status' => $status, 'locale' => $locale];
    }

    /**
     * Speichert eine geprüfte Umleitung (gleiche Quelle → überschreiben) und erledigt die Zeile im 404-Protokoll.
     *
     * @param  array{from: string, to: string, status: int, locale: string}  $checked
     * @return array{0: Redirect, 1: bool} Umleitung und ob sie neu ist
     */
    public function save(Site $site, array $checked, string $source, ?Redirect $existing = null): array
    {
        $redirect = $existing ?? Redirect::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('from_path', $checked['from'])->first();
        $created = $redirect === null;
        $redirect ??= new Redirect(['site_id' => $site->id, 'hits' => 0]);
        $redirect->forceFill(['site_id' => $site->id, 'locale' => $checked['locale'], 'from_path' => $checked['from'], 'to_path' => $checked['to'],
            'status' => $checked['status'], 'source' => $created ? $source : $redirect->source])->save();
        NotFoundHit::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('path', $checked['from'])->delete();

        return [$redirect, $created];
    }

    /** Ein Pfad ohne Host, Abfrage, Anker und Schrägstrich am Ende; null, wenn es keiner ist. */
    public static function path(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('#^https?://#i', $value) === 1) {
            $value = (string) (parse_url($value, PHP_URL_PATH) ?? '/');
        }
        $value = (string) preg_replace('/[?#].*$/', '', $value);
        if ($value === '' || ! str_starts_with($value, '/') || preg_match('/\s/', $value) === 1) {
            return null;
        }

        return '/'.trim($value, '/');
    }
}
