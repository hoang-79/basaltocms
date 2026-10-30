<?php

declare(strict_types=1);

namespace Basalto\Core\Hosting\Acme;

use Carbon\CarbonImmutable;

/** CR-017: ein ausgestelltes Zertifikat — Blatt, Zwischenzertifikate und Schlüssel als PEM, dazu der Ablauf. */
final readonly class Certificate
{
    public function __construct(public string $certificate, public string $chain, public string $privateKey, public CarbonImmutable $expiresAt) {}

    /** @throws AcmeException die Antwort enthält kein lesbares Zertifikat */
    public static function fromChain(string $pem, string $privateKey): self
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $matches);
        $certificates = $matches[0];
        $info = $certificates === [] ? false : openssl_x509_parse($certificates[0]);
        if (! is_array($info) || ! is_int($info['validTo_time_t'] ?? null)) {
            throw new AcmeException('Let\'s Encrypt hat kein lesbares Zertifikat geliefert.');
        }

        return new self($certificates[0]."\n", implode("\n", array_slice($certificates, 1))."\n", $privateKey, CarbonImmutable::createFromTimestamp($info['validTo_time_t']));
    }
}
