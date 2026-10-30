<?php

declare(strict_types=1);

namespace Basalto\Core\Hosting\Acme;

/**
 * CR-017: Antworten auf die Prüfung HTTP-01 als Dateien unter `<Dokumentstamm>/.well-known/acme-challenge/`. Bei cyon
 * liefert der Webserver eine vorhandene Datei dort selbst aus; fehlt sie, leitet er auf den Resolver von cyon um — eine
 * Route der Anwendung erreicht Let's Encrypt deshalb nie. Leere Ordner räumt {@see self::remove()} wieder weg.
 */
final class ChallengeFiles
{
    public function __construct(private readonly string $webroot) {}

    public function put(string $token, string $content): void
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new AcmeException(sprintf('Der Ordner %s lässt sich nicht anlegen.', $directory));
        }
        if (@file_put_contents($directory.'/'.self::name($token), $content) === false) {
            throw new AcmeException(sprintf('Die Prüfdatei in %s lässt sich nicht schreiben.', $directory));
        }
        @chmod($directory.'/'.self::name($token), 0644);
    }

    public function remove(string $token): void
    {
        $directory = $this->directory();
        @unlink($directory.'/'.self::name($token));
        // nur leere Ordner — cyon legt für die eigenen Zertifikate zeitweise ebenfalls Dateien hierhin
        if (is_dir($directory) && (scandir($directory) ?: []) === ['.', '..']) {
            @rmdir($directory);
            @rmdir(dirname($directory));
        }
    }

    private function directory(): string
    {
        return rtrim($this->webroot, '/').'/.well-known/acme-challenge';
    }

    /** Tokens sind base64url — alles andere wäre ein Pfad ausserhalb des Ordners. */
    private static function name(string $token): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{8,}$/', $token) !== 1) {
            throw new AcmeException('Ungültiges Token für die Prüfung.');
        }

        return $token;
    }
}
