<?php

declare(strict_types=1);

namespace Basalto\Core\Extranet;

use Basalto\Core\Core;
use Basalto\Core\Models\Account;
use Basalto\Core\Models\InstanceMove;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Operations\Health;
use Basalto\Core\Saas\CloudDomainLimit;
use Basalto\Core\Saas\Move\InstanceLink;
use Basalto\Core\Saas\Saas;
use Throwable;

/**
 * Zustand einer SaaS-Instanz für den SaaS-Betrieb im Extranet (CR-004, P-15): Bereitschaft wie `/up`, Konten je Plan,
 * Domains mit Cloud-Limit, offene Umzüge, anstehende Löschungen. Die eigene Instanz direkt, die anderen über die
 * signierte Schnittstelle (`BASALTO_INSTANCE_SECRET`).
 *
 * @phpstan-type Summary array{instance: string, reachable: bool, error: string|null, version: string|null, ready: bool, status: string, checks: array<string, array{ok: bool, detail: string}>, accounts: array<string, int>, domains: array{used: int, limit: int, percent: int, warning: bool, pending: int}, moves: int, deletions: int}
 */
final class InstanceSummary
{
    public const SUFFIX = 'zustand';

    /** @return Summary */
    public static function local(): array
    {
        $ready = app(Health::class)->readiness();
        $plans = array_keys((array) config('basalto.saas.plans', []));
        $accounts = Account::query()->whereIn('plan', $plans)->whereNull('erased_at')->selectRaw('plan, count(*) as total')->groupBy('plan')->pluck('total', 'plan')
            ->map(static fn (mixed $n): int => (int) $n)->all();

        return [
            'instance' => Saas::instance(), 'reachable' => true, 'error' => null, 'version' => Core::VERSION, 'ready' => $ready['ok'], 'status' => $ready['status'],
            'checks' => $ready['checks'], 'accounts' => $accounts,
            'domains' => CloudDomainLimit::status() + ['pending' => self::pendingDomains()],
            'moves' => InstanceMove::query()->whereNotIn('status', [InstanceMove::DONE, InstanceMove::CLEANED, InstanceMove::FAILED])->count(),
            'deletions' => Account::query()->whereNull('erased_at')->whereNotNull('delete_after')->count(),
        ];
    }

    /** Domains, die angelegt, aber noch nicht verbunden sind (Cloud-Eintrag ohne Bestätigung). */
    public static function pendingDomains(): int
    {
        return SiteDomain::query()->whereNotNull('cloud_domain_id')->whereNull('verified_at')->count();
    }

    /** @return Summary */
    public static function remote(string $instance): array
    {
        $empty = ['instance' => $instance, 'reachable' => false, 'error' => null, 'version' => null, 'ready' => false, 'status' => '—', 'checks' => [], 'accounts' => [],
            'domains' => ['used' => 0, 'limit' => 0, 'percent' => 0, 'warning' => false, 'pending' => 0], 'moves' => 0, 'deletions' => 0];
        if (! InstanceLink::configured()) {
            return ['error' => __('Ohne BASALTO_INSTANCE_SECRET gibt es keine Verbindung zur Instanz.')] + $empty;
        }
        try {
            $response = app(InstanceLink::class)->get($instance, self::SUFFIX);
            $data = $response->json();
            if (! $response->successful() || ! is_array($data)) {
                return ['error' => __('Antwort :status', ['status' => $response->status()])] + $empty;
            }

            return array_replace($empty, array_intersect_key($data, $empty), ['instance' => $instance, 'reachable' => true, 'error' => null]);
        } catch (Throwable $e) {
            return ['error' => __('nicht erreichbar: :message', ['message' => mb_substr($e->getMessage(), 0, 160)])] + $empty;
        }
    }
}
