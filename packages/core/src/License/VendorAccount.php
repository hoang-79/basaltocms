<?php

declare(strict_types=1);

namespace Basalto\Core\License;

use Basalto\Core\Core;
use Basalto\Core\Models\VendorConnection;
use Basalto\Core\Vendor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * TB 15 Scheibe 4 (CR-004 P-06): das Kundenkonto beim Hersteller aus Sicht der Installation. Verbinden per Mail-Code
 * (`POST /api/installations/code` → `POST /api/installations/connect`), danach Lizenzen, Rechnungen und Marktplatz-
 * Käufe mit dem Token holen (`GET /api/installations/account`) — im Cache (zehn Minuten, ein Fehler eine Minute),
 * kurze Zeitlimits, damit ein nicht erreichbarer Hersteller das Backend nicht aufhält.
 *
 * Die Antwort ist mit dem Marktplatz-Schlüssel signiert (`X-Basalto-Signature`, Ed25519 über den Rohtext) und wird mit
 * `basalto.marketplace.public_key` geprüft: falsch signiert → Fehler, nichts wird angezeigt; ohne Signatur → angezeigt
 * als «unsigniert». Adressen baut die Installation selbst aus `Vendor::url()`; Links aus der Antwort zeigt sie nur,
 * wenn sie auf den Hersteller zeigen. Felder werden mit festen Typen übernommen — die Antwort ist fremd.
 *
 * @phpstan-type License array{key_id: string, plan: string, sublicense: bool, licensee: string, client: string|null, domains: list<string>, issued_at: string|null, updates_until: string|null, status: string, key: string|null}
 * @phpstan-type Invoice array{number: string, issued_at: string|null, due_at: string|null, total: int, currency: string, status: string, pdf_url: string|null}
 * @phpstan-type Purchase array{number: string|null, title: string, kind: string, status: string, amount: int, currency: string, paid_at: string|null, updates_until: string|null, installation: string|null, receipt_url: string|null}
 * @phpstan-type Account array{customer: array{name: string, email: string}, licenses: list<License>, invoices: list<Invoice>, purchases: list<Purchase>}
 * @phpstan-type Result array{ok: bool, signed: bool, error: string|null, revoked: bool, fetched_at: string|null, account: Account|null}
 */
final class VendorAccount
{
    public const CACHE_MINUTES = 10;

    public const SIGNATURE_HEADER = 'X-Basalto-Signature';

    public function connection(): ?VendorConnection
    {
        return VendorConnection::current();
    }

    /**
     * Code anfordern — der Hersteller schickt ihn an die Adresse, wenn es dort ein Kundenkonto gibt.
     *
     * @throws VendorAccountException
     */
    public function requestCode(string $email, string $host): int
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->post(Vendor::url('api/installations/code'), ['email' => $email, 'host' => $host]));
        $this->failUnless($response);
        $minutes = $response->json('minutes');

        return is_int($minutes) ? $minutes : 10;
    }

    /**
     * Mit dem Code verbinden; eine bestehende Verbindung wird ersetzt.
     *
     * @throws VendorAccountException
     */
    public function connect(string $email, string $code, string $host, string $name, ?Authenticatable $user): VendorConnection
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->post(Vendor::url('api/installations/connect'),
            ['email' => $email, 'code' => $code, 'host' => $host, 'name' => $name]));
        if ($response->status() === 422 && $response->json('error') === 'code_invalid') {
            throw new VendorAccountException(__('Der Code ist falsch oder abgelaufen.'));
        }
        $this->failUnless($response);
        $token = $response->json('token');
        if (! is_string($token) || preg_match('/^[A-Za-z0-9_]{20,100}$/', $token) !== 1) {
            throw new VendorAccountException(__('Die Antwort von :host ist unvollständig.', ['host' => Vendor::host()]));
        }
        $name = $response->json('customer.name');
        $this->forget();
        VendorConnection::query()->delete();

        return VendorConnection::query()->create(['vendor_url' => Vendor::url(), 'email' => $email, 'customer_name' => is_string($name) ? mb_substr($name, 0, 160) : null,
            'host' => $host, 'token' => $token, 'connected_by' => $user === null ? null : (int) $user->getAuthIdentifier()]);
    }

    /**
     * Lizenzen, Rechnungen und Käufe — aus dem Cache oder frisch.
     *
     * @return Result
     */
    public function account(bool $fresh = false): array
    {
        $connection = $this->connection();
        if ($connection === null) {
            return ['ok' => false, 'signed' => false, 'error' => null, 'revoked' => false, 'fetched_at' => null, 'account' => null];
        }
        $key = self::cacheKey($connection);
        $cached = $fresh ? null : Cache::get($key);
        if (is_array($cached) && array_key_exists('ok', $cached) && array_key_exists('account', $cached)) {
            /** @var Result $cached */
            return $cached;
        }
        $result = $this->fetch($connection);
        Cache::put($key, $result, $result['ok'] ? self::CACHE_MINUTES * 60 : 60);

        return $result;
    }

    /**
     * Trennen: beim Hersteller widerrufen (wenn erreichbar), hier löschen.
     *
     * @return bool ob der Hersteller den Widerruf bestätigt hat
     */
    public function disconnect(): bool
    {
        $connection = $this->connection();
        if ($connection === null) {
            return true;
        }
        $revoked = false;
        try {
            $response = self::http()->withToken($connection->token)->delete(Vendor::url('api/installations/account'));
            // 401: dort schon widerrufen — für die Installation dasselbe
            $revoked = $response->successful() || $response->status() === 401;
        } catch (ConnectionException) {
            $revoked = false;
        }
        $this->forget();
        VendorConnection::query()->delete();

        return $revoked;
    }

    public function forget(): void
    {
        foreach (VendorConnection::query()->get() as $connection) {
            Cache::forget(self::cacheKey($connection));
        }
    }

    /** @return Result */
    private function fetch(VendorConnection $connection): array
    {
        $fail = static fn (string $error, bool $revoked = false): array => ['ok' => false, 'signed' => false, 'error' => $error, 'revoked' => $revoked,
            'fetched_at' => now()->toIso8601String(), 'account' => null];
        try {
            $response = self::http()->withToken($connection->token)->get(Vendor::url('api/installations/account'));
        } catch (ConnectionException) {
            return $fail(self::unreachable());
        }
        if ($response->status() === 401) {
            return $fail((string) __('Der Hersteller kennt diese Verbindung nicht mehr (dort getrennt). Bitte trennen und neu verbinden.'), true);
        }
        if (! $response->successful()) {
            return $fail(self::statusMessage($response->status()));
        }
        $body = $response->body();
        $signature = $response->header(self::SIGNATURE_HEADER);
        $signed = $signature !== '';
        if ($signed && ! self::verify($body, $signature)) {
            return $fail((string) __('Die Antwort von :host ist nicht korrekt signiert — die Daten werden nicht angezeigt.', ['host' => Vendor::host()]));
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $data = null;
        }
        if (! is_array($data) || ! is_array($data['customer'] ?? null)) {
            return $fail((string) __('Die Antwort von :host ist unvollständig.', ['host' => Vendor::host()]));
        }
        // Gehört die Antwort zu dieser Installation? Eine fremde (abgefangene, wiederholte) Antwort wird nicht gezeigt
        if (! is_array($data['installation'] ?? null) || ($data['installation']['host'] ?? null) !== $connection->host) {
            return $fail((string) __('Die Antwort von :host gehört zu einer anderen Installation — die Daten werden nicht angezeigt.', ['host' => Vendor::host()]));
        }

        return ['ok' => true, 'signed' => $signed, 'error' => null, 'revoked' => false, 'fetched_at' => now()->toIso8601String(), 'account' => self::sanitize($data)];
    }

    private static function verify(string $body, string $signature): bool
    {
        $public = base64_decode(trim((string) config('basalto.marketplace.public_key')), true);
        $raw = base64_decode($signature, true);
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($raw, $body, $public);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Nur bekannte Felder mit festen Typen.
     *
     * @param  array<mixed>  $data
     * @return Account
     */
    private static function sanitize(array $data): array
    {
        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $licenses = [];
        foreach (self::list($data['licenses'] ?? null) as $raw) {
            $keyId = self::string($raw['key_id'] ?? null, 32);
            if ($keyId === null) {
                continue;
            }
            $key = self::string($raw['key'] ?? null, 8000);
            $licenses[] = [
                'key_id' => $keyId,
                'plan' => in_array($raw['plan'] ?? null, [LicenseKey::PLAN_SITE, LicenseKey::PLAN_AGENCY], true) ? (string) $raw['plan'] : LicenseKey::PLAN_SITE,
                'sublicense' => ($raw['sublicense'] ?? false) === true,
                'licensee' => self::string($raw['licensee'] ?? null, 160) ?? '',
                'client' => self::string($raw['client'] ?? null, 160),
                'domains' => array_values(array_filter(array_map(static fn (mixed $d): ?string => self::string($d, 253), self::plain($raw['domains'] ?? null)), 'is_string')),
                'issued_at' => self::date($raw['issued_at'] ?? null),
                'updates_until' => self::date($raw['updates_until'] ?? null),
                'status' => in_array($raw['status'] ?? null, ['active', 'revoked', 'pending'], true) ? (string) $raw['status'] : 'pending',
                'key' => $key !== null && str_starts_with($key, 'BSL1.') ? $key : null,
            ];
        }
        $invoices = [];
        foreach (self::list($data['invoices'] ?? null) as $raw) {
            $number = self::string($raw['number'] ?? null, 40);
            if ($number === null) {
                continue;
            }
            $invoices[] = [
                'number' => $number,
                'issued_at' => self::date($raw['issued_at'] ?? null),
                'due_at' => self::date($raw['due_at'] ?? null),
                'total' => is_int($raw['total'] ?? null) ? $raw['total'] : 0,
                'currency' => self::currency($raw['currency'] ?? null),
                'status' => in_array($raw['status'] ?? null, ['open', 'paid', 'cancelled'], true) ? (string) $raw['status'] : 'open',
                'pdf_url' => self::vendorLink($raw['pdf_url'] ?? null),
            ];
        }
        $purchases = [];
        foreach (self::list($data['purchases'] ?? null) as $raw) {
            $title = self::string($raw['title'] ?? null, 160);
            if ($title === null) {
                continue;
            }
            $purchases[] = [
                'number' => self::string($raw['number'] ?? null, 40),
                'title' => $title,
                'kind' => in_array($raw['kind'] ?? null, ['theme', 'plugin'], true) ? (string) $raw['kind'] : 'plugin',
                'status' => in_array($raw['status'] ?? null, ['open', 'paid', 'cancelled', 'refunded'], true) ? (string) $raw['status'] : 'open',
                'amount' => is_int($raw['amount'] ?? null) ? $raw['amount'] : 0,
                'currency' => self::currency($raw['currency'] ?? null),
                'paid_at' => self::date($raw['paid_at'] ?? null),
                'updates_until' => self::date($raw['updates_until'] ?? null),
                'installation' => self::string($raw['installation'] ?? null, 253),
                'receipt_url' => self::vendorLink($raw['receipt_url'] ?? null),
            ];
        }

        return [
            'customer' => ['name' => self::string($customer['name'] ?? null, 160) ?? '', 'email' => self::string($customer['email'] ?? null, 254) ?? ''],
            'licenses' => $licenses,
            'invoices' => $invoices,
            'purchases' => $purchases,
        ];
    }

    /** @return list<array<mixed>> */
    private static function list(mixed $value): array
    {
        return array_values(array_filter(self::plain($value), 'is_array'));
    }

    /** @return list<mixed> */
    private static function plain(mixed $value): array
    {
        return is_array($value) ? array_values(array_slice($value, 0, 500)) : [];
    }

    private static function string(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private static function currency(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : 'CHF';
    }

    /** Nur Links auf den Hersteller — einer anderen Adresse aus der Antwort folgt die Installation nie. */
    private static function vendorLink(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, Vendor::url().'/') && strlen($value) <= 2000 ? $value : null;
    }

    private static function http(): PendingRequest
    {
        return Http::timeout(6)->connectTimeout(3)->acceptJson()->withUserAgent('Basalto/'.Core::VERSION);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws VendorAccountException
     */
    private function send(callable $call): Response
    {
        try {
            return $call(self::http());
        } catch (ConnectionException) {
            throw new VendorAccountException(self::unreachable());
        }
    }

    /** @throws VendorAccountException */
    private function failUnless(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        if ($response->status() === 422) {
            throw new VendorAccountException(__('Bitte eine gültige E-Mail-Adresse angeben.'));
        }

        throw new VendorAccountException(self::statusMessage($response->status()));
    }

    private static function statusMessage(int $status): string
    {
        return match ($status) {
            404 => (string) __(':host bietet keine Kundenkonten für Installationen an.', ['host' => Vendor::host()]),
            429 => (string) __('Zu viele Versuche — bitte in einer Minute erneut.'),
            default => (string) __(':host antwortet mit :status — bitte später erneut versuchen.', ['host' => Vendor::host(), 'status' => $status]),
        };
    }

    private static function unreachable(): string
    {
        return (string) __(':host ist nicht erreichbar — bitte später erneut versuchen.', ['host' => Vendor::host()]);
    }

    private static function cacheKey(VendorConnection $connection): string
    {
        return 'basalto.vendor-account.'.$connection->id.'.'.md5($connection->vendor_url);
    }
}
