<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai;

/** Die vier Funktionen des KI-Moduls (M-24) — je Site einzeln schaltbar, jede nur auf Klick. */
enum Feature: string
{
    case Improve = 'improve';
    case AltText = 'alt_text';
    case Translate = 'translate';
    case Seo = 'seo';

    public function label(): string
    {
        return match ($this) {
            self::Improve => __('Text verbessern'),
            self::AltText => __('Alt-Texte'),
            self::Translate => __('Übersetzung'),
            self::Seo => __('SEO-Vorschläge'),
        };
    }
}
