<?php

declare(strict_types=1);

namespace Basalto\Core\Rules;

use Basalto\Core\Support\Countries;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Postleitzahl im Muster des Landes (CH vierstellig, DE fünfstellig, GB «SW1A 1AA» …). */
final class PostalCode implements ValidationRule
{
    public function __construct(private readonly string $country) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '' || Countries::validPostalCode($value, $this->country)) {
            return;
        }
        $c = Countries::find($this->country);
        $fail($c !== null && $c['postal_example'] !== null
            ? __('Diese Postleitzahl passt nicht zu :country (z. B. :example).', ['country' => $c['name'], 'example' => (string) $c['postal_example']])
            : __('Diese Postleitzahl ist ungültig.'));
    }
}
