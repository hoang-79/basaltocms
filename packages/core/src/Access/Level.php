<?php

declare(strict_types=1);

namespace Basalto\Core\Access;

/**
 * Stufe eines Rechts je Bereich (CR-005, R-02): geordnet, jede enthält die darunter. «Alles» umfasst auch die
 * Einstellungen des Bereichs (z. B. den Webhook eines Formulars oder den Schlüssel der KI).
 */
enum Level: int
{
    case None = 0;
    case View = 1;
    case Edit = 2;
    case Delete = 3;
    case All = 4;

    public function includes(self $required): bool
    {
        return $this->value >= $required->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => __('keine'),
            self::View => __('ansehen'),
            self::Edit => __('bearbeiten'),
            self::Delete => __('löschen'),
            self::All => __('alles'),
        };
    }

    /** Stufe, die eine Anfrage ohne ausdrückliche Angabe braucht: lesen → ansehen, löschen → löschen, sonst bearbeiten. */
    public static function forMethod(string $method): self
    {
        return match (strtoupper($method)) {
            'GET', 'HEAD', 'OPTIONS' => self::View,
            'DELETE' => self::Delete,
            default => self::Edit,
        };
    }
}
