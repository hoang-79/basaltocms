<?php

declare(strict_types=1);

/*
 * Englische Validierung (N-01): die Meldungen liefert Laravel (lang/en/validation.php des Frameworks); hier stehen
 * nur die Namen der Felder des Kerns, mit denselben Schlüsseln wie lang/de/validation.php.
 */
return [
    'attributes' => [
        'name' => 'name',
        'email' => 'email address',
        'code' => 'code',
        'handle' => 'handle',
        'default_locale' => 'default language',
        'timezone' => 'time zone',
        'theme' => 'theme',
        'title' => 'title',
        'slug' => 'path',
        'locale' => 'language',
        'role' => 'role',
        'key' => 'key',
        'value' => 'value',
        'file' => 'file',
        'host' => 'domain',
        'kind' => 'type',
        'locales.*.locale' => 'language',
        'locales.*.fallback_locale' => 'fallback language',
        'domains.*.host' => 'domain',
        'domains.*.kind' => 'domain type',
        'modules.*' => 'module',
    ],
];
