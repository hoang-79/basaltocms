<?php

declare(strict_types=1);

namespace Basalto\Core\Hosting\Acme;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use OpenSSLAsymmetricKey;

/**
 * CR-017 (TB 87): Zertifikate von Let's Encrypt über ACME (RFC 8555) mit der Prüfung HTTP-01 — ohne Paket, nur mit
 * ext-openssl und dem HTTP-Client der Anwendung. Das Konto ist ein RSA-Schlüssel unter `storage/app/acme/account.key`
 * (0600); mit dem ersten Konto gelten die Nutzungsbedingungen von Let's Encrypt als angenommen — eingeschaltet wird das
 * nur über `BASALTO_HOSTING_PANEL` (Entscheid CR-017 E-1). Jede Anfrage ist mit dem Kontoschlüssel signiert (JWS, RS256),
 * jede Antwort bringt die Einmalzahl für die nächste.
 */
final class AcmeClient
{
    private const POLL_ATTEMPTS = 30;

    /** @var array<string, mixed>|null */
    private ?array $directory = null;

    private ?string $nonce = null;

    private ?string $kid = null;

    private ?OpenSSLAsymmetricKey $key = null;

    public function __construct(private readonly string $directoryUrl, private readonly string $accountKeyPath, private readonly ?string $email = null) {}

    public static function fromConfig(): self
    {
        $email = config('basalto.hosting.acme.email');

        return new self((string) config('basalto.hosting.acme.directory'), storage_path('app/acme/account.key'), is_string($email) && $email !== '' ? $email : null);
    }

    /**
     * Ein Zertifikat für genau einen Host: Auftrag, Prüfung über die Datei im Dokumentstamm, Abschluss mit neuem
     * Schlüssel, Abholen der Kette.
     *
     * @throws AcmeException
     */
    public function issue(string $host, ChallengeFiles $files): Certificate
    {
        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            throw new AcmeException(sprintf('«%s» ist kein gültiger Hostname.', $host));
        }
        $this->account();
        $order = $this->post($this->endpoint('newOrder'), ['identifiers' => [['type' => 'dns', 'value' => $host]]]);
        $orderUrl = (string) $order->header('Location');
        if ($orderUrl === '') {
            throw new AcmeException('Let\'s Encrypt hat keinen Auftrag angelegt.');
        }
        foreach ((array) $order->json('authorizations', []) as $authorization) {
            $this->authorize((string) $authorization, $files);
        }
        $state = $this->await($orderUrl, ['ready', 'valid']);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false || ! openssl_pkey_export($key, $keyPem)) {
            throw new AcmeException('Der Schlüssel für das Zertifikat lässt sich nicht erzeugen.');
        }
        if ($state['status'] === 'ready') {
            $this->post((string) ($state['finalize'] ?? ''), ['csr' => self::base64url(self::csr($host, $key))]);
            $state = $this->await($orderUrl, ['valid']);
        }

        return Certificate::fromChain($this->post((string) ($state['certificate'] ?? ''), null, 'application/pem-certificate-chain')->body(), $keyPem);
    }

    /** Fingerabdruck des Kontoschlüssels (RFC 7638) — Teil jeder Antwort auf eine Prüfung. */
    public function thumbprint(): string
    {
        return self::base64url(hash('sha256', (string) json_encode($this->jwk()), true));
    }

    private function account(): void
    {
        if ($this->kid !== null) {
            return;
        }
        $payload = ['termsOfServiceAgreed' => true];
        if ($this->email !== null) {
            $payload['contact'] = ['mailto:'.$this->email];
        }
        $response = $this->post($this->endpoint('newAccount'), $payload);
        $this->kid = (string) $response->header('Location');
        if ($this->kid === '') {
            throw new AcmeException('Let\'s Encrypt hat kein Konto zurückgegeben.');
        }
    }

    private function authorize(string $url, ChallengeFiles $files): void
    {
        $authorization = (array) $this->post($url, null)->json();
        if (($authorization['status'] ?? null) === 'valid') {
            return;
        }
        $challenge = collect((array) ($authorization['challenges'] ?? []))->first(static fn (mixed $c): bool => is_array($c) && ($c['type'] ?? null) === 'http-01');
        if (! is_array($challenge)) {
            throw new AcmeException('Let\'s Encrypt bietet keine Prüfung über http an.');
        }
        $token = (string) ($challenge['token'] ?? '');
        $files->put($token, $token.'.'.$this->thumbprint());
        try {
            $this->post((string) ($challenge['url'] ?? ''), (object) []);
            $this->await($url, ['valid']);
        } finally {
            $files->remove($token);
        }
    }

    /**
     * Den Stand eines Auftrags oder einer Prüfung abfragen, bis er eines der Ziele erreicht.
     *
     * @param  list<string>  $targets
     * @return array<string, mixed>
     */
    private function await(string $url, array $targets): array
    {
        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $state = (array) $this->post($url, null)->json();
            $status = (string) ($state['status'] ?? '');
            if (in_array($status, $targets, true)) {
                return $state;
            }
            if ($status === 'invalid') {
                throw new AcmeException('Let\'s Encrypt: '.self::detail($state));
            }
            Sleep::for(2)->seconds();
        }

        throw new AcmeException('Let\'s Encrypt hat nicht rechtzeitig geantwortet.');
    }

    /**
     * Signierte Anfrage (JWS). `null` als Inhalt ist «POST-as-GET» (leerer Inhalt), `(object) []` ein leeres Objekt.
     *
     * @param  array<string, mixed>|object|null  $payload
     */
    private function post(string $url, array|object|null $payload, string $accept = 'application/json', bool $retry = true): Response
    {
        if ($url === '') {
            throw new AcmeException('Let\'s Encrypt hat eine Adresse ausgelassen.');
        }
        $protected = ['alg' => 'RS256', 'nonce' => $this->nonce(), 'url' => $url] + ($this->kid === null ? ['jwk' => $this->jwk()] : ['kid' => $this->kid]);
        $header = self::base64url((string) json_encode($protected, JSON_UNESCAPED_SLASHES));
        $body = $payload === null ? '' : self::base64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        if (! openssl_sign($header.'.'.$body, $signature, $this->key(), OPENSSL_ALGO_SHA256)) {
            throw new AcmeException('Die Anfrage an Let\'s Encrypt lässt sich nicht signieren.');
        }
        try {
            $response = Http::timeout(30)->withHeaders(['Accept' => $accept])
                ->withBody((string) json_encode(['protected' => $header, 'payload' => $body, 'signature' => self::base64url($signature)]), 'application/jose+json')->post($url);
        } catch (ConnectionException $e) {
            throw new AcmeException('Let\'s Encrypt ist nicht erreichbar: '.$e->getMessage());
        }
        $nonce = $response->header('Replay-Nonce');
        $this->nonce = $nonce !== '' ? $nonce : null;
        if ($response->failed()) {
            $problem = (array) $response->json();
            if ($retry && ($problem['type'] ?? null) === 'urn:ietf:params:acme:error:badNonce') {
                return $this->post($url, $payload, $accept, false);
            }

            throw new AcmeException('Let\'s Encrypt: '.self::detail($problem, $response->status()));
        }

        return $response;
    }

    private function nonce(): string
    {
        if ($this->nonce !== null) {
            $nonce = $this->nonce;
            $this->nonce = null;

            return $nonce;
        }
        try {
            $nonce = Http::timeout(30)->head((string) $this->endpoint('newNonce'))->header('Replay-Nonce');
        } catch (ConnectionException $e) {
            throw new AcmeException('Let\'s Encrypt ist nicht erreichbar: '.$e->getMessage());
        }
        if ($nonce === '') {
            throw new AcmeException('Let\'s Encrypt hat keine Einmalzahl geliefert.');
        }

        return $nonce;
    }

    private function endpoint(string $name): string
    {
        if ($this->directory === null) {
            try {
                $response = Http::timeout(30)->acceptJson()->get($this->directoryUrl);
            } catch (ConnectionException $e) {
                throw new AcmeException('Let\'s Encrypt ist nicht erreichbar: '.$e->getMessage());
            }
            if ($response->failed() || ! is_array($response->json())) {
                throw new AcmeException(sprintf('Das Verzeichnis %s antwortet nicht (%d).', $this->directoryUrl, $response->status()));
            }
            $this->directory = $response->json();
        }
        $url = $this->directory[$name] ?? null;
        if (! is_string($url) || $url === '') {
            throw new AcmeException(sprintf('Das Verzeichnis von Let\'s Encrypt nennt «%s» nicht.', $name));
        }

        return $url;
    }

    /** Der Kontoschlüssel — beim ersten Mal erzeugt und nur für den Besitzer lesbar abgelegt. */
    private function key(): OpenSSLAsymmetricKey
    {
        if ($this->key !== null) {
            return $this->key;
        }
        $pem = is_file($this->accountKeyPath) ? file_get_contents($this->accountKeyPath) : false;
        $key = is_string($pem) ? openssl_pkey_get_private($pem) : false;
        if ($key === false) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            $directory = dirname($this->accountKeyPath);
            if ($key === false || ! openssl_pkey_export($key, $pem) || (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))
                || @file_put_contents($this->accountKeyPath, $pem) === false) {
                throw new AcmeException('Der Kontoschlüssel für Let\'s Encrypt lässt sich nicht anlegen.');
            }
            @chmod($this->accountKeyPath, 0600);
        }

        return $this->key = $key;
    }

    /** @return array{e: string, kty: string, n: string} öffentlicher Schlüssel als JWK — Schlüssel in dieser Reihenfolge (RFC 7638) */
    private function jwk(): array
    {
        $details = openssl_pkey_get_details($this->key());
        $rsa = is_array($details) ? (array) ($details['rsa'] ?? []) : [];

        return ['e' => self::base64url((string) ($rsa['e'] ?? '')), 'kty' => 'RSA', 'n' => self::base64url((string) ($rsa['n'] ?? ''))];
    }

    /** Zertifikatsantrag (DER) mit dem Host als Name und als subjectAltName. */
    private static function csr(string $host, OpenSSLAsymmetricKey $key): string
    {
        $config = (string) tempnam(sys_get_temp_dir(), 'acme');
        file_put_contents($config, "[req]\ndistinguished_name = dn\nreq_extensions = san\n[dn]\n[san]\nsubjectAltName = DNS:{$host}\n");
        try {
            $csr = openssl_csr_new(['commonName' => $host], $key, ['config' => $config, 'digest_alg' => 'sha256', 'req_extensions' => 'san']);
            if ($csr === false || $csr === true || ! openssl_csr_export($csr, $pem)) {
                throw new AcmeException('Der Zertifikatsantrag lässt sich nicht erzeugen.');
            }
        } finally {
            @unlink($config);
        }

        return (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem));
    }

    /** @param  array<array-key, mixed>  $problem */
    private static function detail(array $problem, ?int $status = null): string
    {
        $error = is_array($problem['error'] ?? null) ? $problem['error'] : $problem;
        foreach ((array) ($problem['challenges'] ?? []) as $challenge) {
            if (is_array($challenge) && is_array($challenge['error'] ?? null)) {
                $error = $challenge['error'];
            }
        }
        $detail = is_string($error['detail'] ?? null) ? $error['detail'] : 'unbekannter Fehler';

        return $status !== null ? sprintf('%s (%d)', $detail, $status) : $detail;
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
