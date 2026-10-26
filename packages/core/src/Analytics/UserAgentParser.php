<?php

declare(strict_types=1);

namespace Basalto\Core\Analytics;

/**
 * TB 54: Leichtes Lesen des User-Agents ohne Abhängigkeit (wie Fristo) — Browser, Betriebssystem, Gerät und ob es ein
 * Bot ist. Reicht für die Statistik (häufigste Browser, Systeme, Geräte).
 */
final class UserAgentParser
{
    /** @return array{browser: string, os: string, device_type: string, is_bot: bool} */
    public static function parse(?string $agent): array
    {
        $agent = (string) $agent;

        return ['browser' => self::browser($agent), 'os' => self::os($agent), 'device_type' => self::deviceType($agent), 'is_bot' => self::isBot($agent)];
    }

    public static function isBot(string $agent): bool
    {
        if ($agent === '') {
            return true;
        }

        return preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|pingdom|uptimerobot|headless|lighthouse|curl|wget|python-requests|'
            .'go-http-client|axios|monitor|preview|whatsapp|telegram|discordbot/i', $agent) === 1;
    }

    private static function browser(string $agent): string
    {
        // Edge, Opera und Samsung vor Chrome; Chrome vor Safari
        return match (true) {
            preg_match('/Edg(e|A|iOS)?\//i', $agent) === 1 => 'Edge',
            preg_match('/OPR\/|Opera/i', $agent) === 1 => 'Opera',
            preg_match('/SamsungBrowser/i', $agent) === 1 => 'Samsung Internet',
            preg_match('/Firefox\/|FxiOS/i', $agent) === 1 => 'Firefox',
            preg_match('/Chrome\/|CriOS/i', $agent) === 1 => 'Chrome',
            preg_match('/Safari\//i', $agent) === 1 && preg_match('/Version\//i', $agent) === 1 => 'Safari',
            $agent === '' => 'Unbekannt',
            default => 'Andere',
        };
    }

    private static function os(string $agent): string
    {
        return match (true) {
            preg_match('/Windows NT/i', $agent) === 1 => 'Windows',
            preg_match('/iPhone|iPad|iPod/i', $agent) === 1 => 'iOS',
            preg_match('/Mac OS X|Macintosh/i', $agent) === 1 => 'macOS',
            preg_match('/Android/i', $agent) === 1 => 'Android',
            preg_match('/CrOS/i', $agent) === 1 => 'ChromeOS',
            preg_match('/Linux/i', $agent) === 1 => 'Linux',
            default => 'Unbekannt',
        };
    }

    private static function deviceType(string $agent): string
    {
        return match (true) {
            self::isBot($agent) => 'bot',
            preg_match('/iPad|Tablet|PlayBook|Silk/i', $agent) === 1 => 'tablet',
            preg_match('/Mobi|iPhone|Android.*Mobile|Windows Phone|IEMobile/i', $agent) === 1 => 'mobile',
            default => 'desktop',
        };
    }
}
