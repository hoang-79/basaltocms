<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Auth\LoginLinks;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/** Einmal-Link für ein bestehendes Konto — für Installationen ohne Mail und für den Notfall (Fristo-Muster). */
final class LoginLinkCommand extends Command
{
    protected $signature = 'basalto:login-link {email : E-Mail des Kontos} {--minutes=15 : Gültigkeit in Minuten}';

    protected $description = 'Erzeugt einen Einmal-Link zur Anmeldung für ein Konto';

    public function handle(LoginLinks $links): int
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $user = $userModel::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->components->error('Kein Konto mit dieser E-Mail-Adresse');

            return self::FAILURE;
        }
        $this->line($links->create((int) $user->getKey(), 'login', (int) $this->option('minutes')));

        return self::SUCCESS;
    }
}
