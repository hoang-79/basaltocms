<?php

declare(strict_types=1);

namespace Basalto\Lava\Runtime;

use Closure;
use LogicException;

/**
 * Die Registrierung aus Sicht eines Moduls (L-12): jeder Name trägt das Handle als Präfix («example.cart»),
 * ein Name ohne oder mit fremdem Präfix und jede Kollision mit einem belegten Namen wirft LogicException.
 */
final class ModuleRegistry
{
    public function __construct(private readonly Registry $registry, private readonly string $handle) {}

    public function function(string $name, Closure $fn): self
    {
        $this->registry->registerScoped('function', $this->qualified($name, 'Funktion'), $fn);

        return $this;
    }

    public function tag(string $name, Closure $fn): self
    {
        $this->registry->registerScoped('tag', $this->qualified($name, 'Tag'), $fn);

        return $this;
    }

    /** @param  array<string, mixed>  $options */
    public function fieldType(string $name, array $options = []): self
    {
        $this->registry->registerScoped('fieldType', $this->qualified($name, 'Feldtyp'), $options);

        return $this;
    }

    /** Eine zusätzliche Eigenschaft eines Schlüsselworts, z. B. property('type', 'example.sku'). */
    public function property(string $keyword, string $name): self
    {
        $this->registry->registerScoped('property', $this->qualified($name, 'Eigenschaft'), null, $keyword);

        return $this;
    }

    /**
     * Eine Art von Lava-Teil des Moduls, z. B. part('shop.cart', 'Warenkorb', ['source'], fn (array $settings, array $vars, string $lang): string => …).
     *
     * @param  list<string>  $settings
     */
    public function part(string $name, string $label, array $settings, Closure $render): self
    {
        $this->registry->registerScoped('part', $this->qualified($name, 'Teil'), ['label' => $label, 'settings' => $settings, 'origin' => 'Modul '.$this->handle, 'render' => $render]);

        return $this;
    }

    public function handle(): string
    {
        return $this->handle;
    }

    private function qualified(string $name, string $label): string
    {
        $prefix = $this->handle.'.';
        if (! str_contains($name, '.')) {
            throw new LogicException(sprintf('%s «%s»: Modul «%s» registriert nur unter seinem Präfix («%s%s»)', $label, $name, $this->handle, $prefix, $name));
        }
        if (! str_starts_with($name, $prefix)) {
            throw new LogicException(sprintf('%s «%s»: fremder Präfix — Modul «%s» darf nur «%s…» registrieren', $label, $name, $this->handle, $prefix));
        }
        if (! preg_match('/^[a-z][a-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new LogicException(sprintf('%s «%s»: ungültiger Name — erlaubt ist «%s<name>» mit Buchstaben, Ziffern und Unterstrich', $label, $name, $prefix));
        }

        return $name;
    }
}
