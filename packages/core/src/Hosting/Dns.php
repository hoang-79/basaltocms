<?php

declare(strict_types=1);

namespace Basalto\Core\Hosting;

/** CR-017: Auflösung über den Resolver des Servers — eigene Klasse, damit Tests sie ersetzen können. */
class Dns
{
    /** @return list<string> IPv4-Adressen des Hosts, leer, wenn er nicht auflöst */
    public function addresses(string $host): array
    {
        $addresses = gethostbynamel($host);

        return $addresses === false ? [] : array_values(array_unique($addresses));
    }
}
