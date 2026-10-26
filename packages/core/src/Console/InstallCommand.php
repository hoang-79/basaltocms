<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Help\LegalTexts;
use Basalto\Core\Install\Installer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

/**
 * Erst-Setup (K-05): Datenbank migrieren, erste Site mit Standardsprache, erstes Konto als Betreiber,
 * Einmal-Link für die erste Anmeldung — ohne Mail-Server. Läuft mehrfach gefahrlos.
 */
final class InstallCommand extends Command
{
    protected $signature = 'basalto:install
        {--site= : Handle der ersten Site (z. B. nguyen-development)}
        {--name= : Name der Site}
        {--locale=de : Standardsprache der Site}
        {--timezone=Europe/Zurich : Zeitzone der Site}
        {--user= : Name des ersten Kontos}
        {--email= : E-Mail des ersten Kontos}
        {--minutes=60 : Gültigkeit des Einmal-Links in Minuten}';

    protected $description = 'Richtet Basalto ein: Datenbank, erste Site, erstes Konto, Einmal-Link zur Anmeldung';

    public function handle(Installer $installer): int
    {
        $this->components->info('Basalto einrichten');
        // TB 11 Scheibe 3 (G-07, Lizenzvertrag § 2 Abs. 2): Hinweis vor der Installation, wie im Browser
        $this->line('  Mit der Installation gilt der Basalto-Lizenzvertrag: '.LegalTexts::publicUrl('lizenzvertrag', 'de').' (auch in LICENSE.md)');
        $this->newLine();

        // still über den Befehl selbst — ein Artisan::call ersetzte die Ausgabe dieses Befehls (Artisan::output())
        $this->components->task('Datenbank migrieren', function (): bool {
            $this->callSilently('migrate', ['--force' => true]);

            return true;
        });

        $handle = Str::slug((string) ($this->option('site') ?: $this->answer('site', 'Handle der ersten Site', 'meine-site')));
        $name = (string) ($this->option('name') ?: $this->answer('name', 'Name der Site', Str::headline($handle)));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->answer('email', 'E-Mail des ersten Kontos'))));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('Keine gültige E-Mail-Adresse: '.$email);

            return self::FAILURE;
        }
        $userName = (string) ($this->option('user') ?: $this->answer('user', 'Name des ersten Kontos', Str::before($email, '@')));
        $result = $installer->install($handle, $name, (string) $this->option('locale'), (string) $this->option('timezone'), $userName, $email, (int) $this->option('minutes'));
        $site = $result['site'];
        $this->components->twoColumnDetail('Site', sprintf('%s (%s, %s)%s', $site->name, $site->handle, $site->default_locale, $result['site_created'] ? '' : ' — vorhanden'));
        $this->components->twoColumnDetail('Konto', sprintf('%s <%s> · Betreiber%s', $result['user']->getAttribute('name'), $email, $result['user_created'] ? '' : ' — vorhanden'));

        $this->newLine();
        $this->components->info(sprintf('Einmal-Link zur ersten Anmeldung (%d Minuten gültig, nur einmal verwendbar):', (int) $this->option('minutes')));
        $this->line('  '.$result['url']);
        $this->newLine();
        $this->components->bulletList([
            'Link im Browser öffnen → angemeldet, ohne Passwort und ohne Mail-Server',
            'Danach im Backend einen Passkey einrichten (Übersicht → «Passkey einrichten»)',
            'Weitere Links jederzeit: php artisan basalto:login-link '.$email,
        ]);

        return self::SUCCESS;
    }

    private function answer(string $what, string $question, ?string $default = null): string
    {
        if (! $this->input->isInteractive()) {
            if ($default !== null) {
                return $default;
            }
            $this->components->error(sprintf('Option --%s fehlt (nicht interaktiv)', $what));
            throw new \RuntimeException(sprintf('Option --%s fehlt', $what));
        }

        return text(label: $question, default: $default ?? '', required: true);
    }
}
