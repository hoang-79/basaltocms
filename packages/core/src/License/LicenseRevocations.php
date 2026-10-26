<?php

declare(strict_types=1);

namespace Basalto\Core\License;

/**
 * Die Sperrliste (K-18): Schlüssel-IDs, die nicht mehr gelten (Rückerstattung, Missbrauch, Ersatz). Sie kommt mit jedem
 * Update des Kerns als Datei mit — geprüft wird offline, ohne Aufruf nach Hause. Ein gesperrter Schlüssel zählt wie
 * kein Schlüssel (Free), mit eigenem Hinweis.
 */
final class LicenseRevocations
{
    /** @var list<string>|null */
    private ?array $ids = null;

    public function __construct(private readonly ?string $path = null) {}

    public static function default(): self
    {
        return new self((string) (config('basalto.license.revocations') ?: dirname(__DIR__, 2).'/resources/license/revoked.json'));
    }

    public function revoked(string $id): bool
    {
        return $id !== '' && in_array($id, $this->ids(), true);
    }

    /** @return list<string> */
    public function ids(): array
    {
        if ($this->ids !== null) {
            return $this->ids;
        }
        $data = $this->path !== null && is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
        $ids = [];
        foreach (is_array($data) && is_array($data['keys'] ?? null) ? $data['keys'] : [] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $this->ids = $ids;
    }

    /** Eine ID in die Datei schreiben (Pflege auf Seite des Herstellers; die Datei geht mit dem nächsten Release hinaus). */
    public function add(string $id): bool
    {
        if ($this->path === null || $this->revoked($id)) {
            return false;
        }
        $data = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : [];
        $data = is_array($data) ? $data : [];
        $data['keys'] = [...$this->ids(), $id];
        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->ids = null;

        return true;
    }
}
