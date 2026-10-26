<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Hub;

/**
 * Das Register der Bereiche des Kundenkontos (TB 48). Module melden ihre Bereiche beim Start an
 * (`app(AccountAreas::class)->add(LicensesArea::class)`); gehalten werden nur Klassennamen, die Bereiche entstehen je
 * Ausgabe neu — darum ist das Register als Singleton auch unter Octane unbedenklich.
 */
final class AccountAreas
{
    /** @var list<class-string<AccountArea>> */
    private array $classes = [];

    /** @param  class-string<AccountArea>  $class */
    public function add(string $class): void
    {
        if (! in_array($class, $this->classes, true)) {
            $this->classes[] = $class;
        }
    }

    /**
     * Die Bereiche, frisch erzeugt und nach Position sortiert — auf Wunsch nur bestimmte Kennungen.
     *
     * @param  list<string>|null  $only
     * @return list<AccountArea>
     */
    public function resolve(?array $only = null): array
    {
        $areas = array_map(static fn (string $class): AccountArea => app($class), $this->classes);
        if ($only !== null) {
            $areas = array_values(array_filter($areas, static fn (AccountArea $a): bool => in_array($a->key(), $only, true)));
        }
        usort($areas, static fn (AccountArea $a, AccountArea $b): int => $a->position() <=> $b->position());

        return $areas;
    }
}
