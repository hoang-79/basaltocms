<?php

declare(strict_types=1);

namespace Basalto\Core\Support;

use Illuminate\Translation\Translator;

/**
 * Der Übersetzer von Basalto: Die Schlüssel sind deutsche Texte. Laravel nimmt für Mehrzahlformen (`trans_choice`)
 * die Ersatzsprache, sobald die Zielsprache den Schlüssel nicht kennt — auf deutschen Seiten stand so «2 days» statt
 * «2 Tage». Für die Sprache der Schlüssel gilt der Schlüssel selbst; für Französisch und Italienisch bleibt der
 * Rückfall auf Englisch.
 */
final class SourceLocaleTranslator extends Translator
{
    public const SOURCE = 'de';

    public static function from(Translator $translator): self
    {
        $copy = new self($translator->getLoader(), $translator->getLocale());
        $copy->setFallback($translator->getFallback());

        return $copy;
    }

    /**
     * @param  string  $key
     * @param  string|null  $locale
     */
    protected function localeForChoice($key, $locale): string
    {
        $locale = $locale ?: $this->locale;

        return $locale === self::SOURCE || $this->hasForLocale($key, $locale) ? $locale : $this->fallback;
    }
}
