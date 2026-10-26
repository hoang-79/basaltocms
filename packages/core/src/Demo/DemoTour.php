<?php

declare(strict_types=1);

namespace Basalto\Core\Demo;

/**
 * Die Funktionsschau der Demo (CR-004, P-07): welche Anforderung des Katalogs auf welcher Seite gezeigt wird — und
 * was die Demo bewusst nicht zeigt, mit Grund. Die Seiten selbst stehen als Lava-Seed in `resources/demo/tour.lava`
 * und werden beim Neuaufsetzen als Lava der Demo-Site eingespielt. Der Abdeckungstest prüft beides gegen den Katalog:
 * jede Anforderung genau einmal, keine Seite zu Ungebautem.
 */
final class DemoTour
{
    /** Pfad der Lava-Datei, wie sie in der Demo-Site liegt. */
    public const LAVA_PATH = 'demo.lava';

    /** @var array<string, list<string>> Seite des Seeds → Anforderungen */
    public const PAGES = [
        'funktionen' => ['G-10'],
        'seiten' => ['M-01', 'M-02', 'K-01', 'K-02'],
        'editor' => ['M-03', 'M-04', 'M-05', 'M-19'],
        'medien' => ['K-08', 'K-09', 'K-10', 'K-11'],
        'sprachen' => ['M-06', 'N-01'],
        'navigation' => ['M-07', 'L-08'],
        'freigabe' => ['M-08', 'M-09', 'K-14', 'K-16'],
        'formulare' => ['M-11'],
        'suche-blog' => ['M-12', 'M-13'],
        'seo' => ['M-10', 'M-16', 'N-03'],
        'themes' => ['M-15', 'M-28', 'L-01', 'L-02', 'L-03', 'L-04', 'L-05', 'L-06', 'L-07', 'L-09', 'L-10', 'L-11', 'L-15', 'L-16', 'L-17', 'K-20', 'N-02'],
        'benutzer' => ['K-03', 'K-04', 'K-05', 'K-06', 'K-13', 'M-25', 'N-12'],
        'verwaltung' => ['K-07', 'K-15', 'K-17', 'K-18', 'B-09', 'G-02'],
        'recht' => ['M-14', 'N-05'],
        'module' => ['K-12', 'L-12', 'M-23', 'M-24', 'M-27'],
        // CR-006 / TB 42: das Forum (Anforderungen FO-01 bis FO-30 stehen im eigenen Katalog von CR-006)
        'community' => [],
        'daten' => ['M-17', 'M-18'],
        // TB 20: Webhilfe im Backend — Panel, Suche über die Doku, Tastenkürzel
        'hilfe' => ['N-09'],
    ];

    /** @var array<string, string> Anforderung → warum die Demo sie nicht zeigt */
    public const NOT_SHOWN = [
        'K-19' => 'Mehrere Datenbanken — Technik im Hintergrund',
        'L-13' => 'Neuladen beim Theme-Bau — Werkzeug der Entwicklung',
        'L-14' => 'Sprachdienst — Erweiterung für VS Code und PhpStorm',
        'M-20' => 'Marktplatz-Verwaltung — nur beim Hersteller',
        'M-21' => 'SaaS — eigene Instanzen mit Registrierung und Abo',
        'M-22' => 'Shop-Anbindung — zurückgestellt',
        'M-26' => 'Testmanagement — Werkzeug der Entwicklung',
        'G-01' => 'Lizenz des Codes — Vertrag', 'G-03' => 'Preise — Geschäft', 'G-04' => 'Lizenzkauf — beim Hersteller',
        'G-05' => 'Update-Anspruch — Vertrag', 'G-06' => 'Marktplatz-Regeln — beim Hersteller', 'G-07' => 'Rechtstexte des Herstellers',
        'G-08' => 'Produkt-Site des Herstellers', 'G-09' => 'Community auf GitHub', 'G-11' => 'SaaS-Domains — eigene Instanzen',
        'B-01' => 'Stack', 'B-02' => 'Monorepo', 'B-03' => 'Laravel Cloud', 'B-04' => 'Flüchtiges Dateisystem', 'B-05' => 'Einfaches Hosting',
        'B-06' => 'Octane', 'B-07' => 'Qualitätskette', 'B-08' => 'Entwicklungsumgebung', 'B-10' => 'Backups', 'B-11' => 'Zero-Downtime',
        'B-12' => 'Laravel ausreizen',
        'N-04' => 'Sicherheit — überall, keine eigene Seite', 'N-06' => 'Time-to-Site — Test mit Personen', 'N-07' => 'Testabdeckung',
        'N-08' => 'Skalierung — Lasttest', 'N-10' => 'Vorgehen und Nachweis', 'N-11' => 'Mockup vor dem Bau',
    ];

    /**
     * Die Lava-Datei mit den Seiten der Funktionsschau. Das Stylesheet der Demo liegt unter einer festen Adresse
     * (`/vendor/basalto/core/demo/demo.css`); damit Browser nach einer Änderung neu laden, bekommt es die Prüfsumme der
     * Datei als `?v=…` angehängt.
     */
    public static function lava(): string
    {
        $root = dirname(__DIR__, 2).'/resources/demo';
        $css = $root.'/assets/demo.css';
        $version = is_file($css) ? substr((string) md5_file($css), 0, 12) : '0';

        return str_replace('/vendor/basalto/core/demo/demo.css"', '/vendor/basalto/core/demo/demo.css?v='.$version.'"', (string) file_get_contents($root.'/tour.lava'));
    }

    /**
     * Ziel nach dem Schnellzugang: nur Pfade im Backend dieser Installation, sonst null.
     */
    public static function target(mixed $value): ?string
    {
        $backend = '/'.trim((string) config('basalto.backend.path', 'admin'), '/');
        if (! is_string($value) || preg_match('#^'.preg_quote($backend, '#').'(/[A-Za-z0-9_\-/.]*)?$#', $value) !== 1 || str_contains($value, '//') || str_contains($value, '..')) {
            return null;
        }

        return $value;
    }
}
