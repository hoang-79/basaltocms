<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Closure;

/**
 * Inhalte der Module, auf die sich Bausteine des Kerns beziehen (CR-006 TB 38): Reaktionen, Abos, Anhänge. Ein Modul
 * meldet beim Booten je Art (`forum_post`, `forum_thread`) an, wer lesen darf, wem der Inhalt gehört und was nach einer
 * Reaktion geschehen soll (Zähler pflegen, benachrichtigen). Danach nur gelesen.
 */
final class Subjects
{
    /** @var array<string, array{readable: Closure, owner: Closure, reacted: Closure|null}> je Art die angemeldeten Rückrufe (Signaturen siehe register()) */
    private array $types = [];

    /**
     * @param  Closure(int, ?Customer): bool  $readable
     * @param  Closure(int): ?int  $owner  Kundenkonto, dem der Inhalt gehört
     * @param  (Closure(int, Customer, ?string): void)|null  $reacted  nach dem Setzen (Art) oder Entfernen (null) einer Reaktion
     */
    public function register(string $type, Closure $readable, Closure $owner, ?Closure $reacted = null): void
    {
        $this->types[$type] = ['readable' => $readable, 'owner' => $owner, 'reacted' => $reacted];
    }

    public function known(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function readable(string $type, int $id, ?Customer $customer): bool
    {
        return isset($this->types[$type]) && (bool) ($this->types[$type]['readable'])($id, $customer);
    }

    public function owner(string $type, int $id): ?int
    {
        $owner = isset($this->types[$type]) ? ($this->types[$type]['owner'])($id) : null;

        return is_numeric($owner) ? (int) $owner : null;
    }

    public function reacted(string $type, int $id, Customer $customer, ?string $reaction): void
    {
        $callback = $this->types[$type]['reacted'] ?? null;
        if ($callback !== null) {
            $callback($id, $customer, $reaction);
        }
    }
}
