<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\InstanceMove;
use Basalto\Core\Saas\Move\InstanceLink;
use Basalto\Core\Saas\Move\InstanceMover;
use Basalto\Core\Saas\Saas;
use Illuminate\Console\Command;

/**
 * `basalto:saas-move` (TB 12 Scheibe 4): Umzüge für den Betrieb — ohne Argument die Liste der letzten, mit Konto und
 * `--to=` einen Umzug von Hand anstossen (z. B. nach einem behobenen Fehler).
 */
final class SaasMoveCommand extends Command
{
    protected $signature = 'basalto:saas-move {account? : Kennung des Kontos} {--to= : Zielinstanz (start oder pro)}';

    protected $description = 'Umzüge zwischen den SaaS-Instanzen anzeigen oder ein Konto von Hand umziehen';

    public function handle(InstanceMover $mover): int
    {
        if (! Saas::enabled() || ! InstanceLink::configured()) {
            $this->components->error('SaaS-Modus und BASALTO_INSTANCE_SECRET (mindestens 32 Zeichen) sind nötig.');

            return self::FAILURE;
        }
        $id = $this->argument('account');
        if ($id === null) {
            $this->table(['UUID', 'Richtung', 'Konto', 'Von', 'Nach', 'Stand', 'Fehler', 'Angelegt'], InstanceMove::query()->latest('id')->limit(20)->get()
                ->map(static fn (InstanceMove $m): array => [$m->uuid, $m->direction, $m->account_id, $m->source, $m->target, $m->status, (string) $m->error, $m->created_at->format('d.m.Y H:i')])->all());

            return self::SUCCESS;
        }
        $account = Account::query()->find((int) $id);
        $target = (string) $this->option('to');
        if ($account === null || $target === '') {
            $this->components->error('Konto und --to= angeben.');

            return self::FAILURE;
        }
        $move = $mover->request($account, $target);
        if ($move === null) {
            $this->components->warn('Kein Umzug angestossen (Ziel = diese Instanz, unbekannt, schon offen oder Konto nicht aktiv).');

            return self::FAILURE;
        }
        $this->components->info(sprintf('Umzug %s nach «%s» angestossen — Stand: %s.', $move->uuid, $target, $move->refresh()->status));

        return self::SUCCESS;
    }
}
