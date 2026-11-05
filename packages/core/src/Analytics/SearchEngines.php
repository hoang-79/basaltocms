<?php

declare(strict_types=1);

namespace Basalto\Core\Analytics;

/**
 * Suchmaschinen und Crawler erkennen (CR-019, TB 108): aus der Herkunft eines Besuchs («www.google.ch» → Google) und
 * aus der Kennung eines Bots («Googlebot/2.1» → Googlebot). Für die Statistik genügt der Name — nichts davon geht
 * nach aussen.
 */
final class SearchEngines
{
    /** Muster des Hosts → Name der Suchmaschine (erster Treffer gilt). */
    private const ENGINES = [
        '/(^|\.)google\.[a-z.]+$/' => 'Google',
        '/(^|\.)bing\.com$/' => 'Bing',
        '/(^|\.)duckduckgo\.com$/' => 'DuckDuckGo',
        '/(^|\.)ecosia\.org$/' => 'Ecosia',
        '/(^|\.)search\.yahoo\.com$|(^|\.)yahoo\.[a-z.]+$/' => 'Yahoo',
        '/(^|\.)yandex\.[a-z.]+$/' => 'Yandex',
        '/(^|\.)startpage\.com$/' => 'Startpage',
        '/(^|\.)qwant\.com$/' => 'Qwant',
        '/(^|\.)search\.brave\.com$/' => 'Brave Search',
        '/(^|\.)swisscows\.(ch|com)$/' => 'Swisscows',
        '/(^|\.)baidu\.com$/' => 'Baidu',
        '/(^|\.)seznam\.cz$/' => 'Seznam',
        '/(^|\.)naver\.com$/' => 'Naver',
        '/(^|\.)perplexity\.ai$/' => 'Perplexity',
        '/(^|\.)chatgpt\.com$/' => 'ChatGPT',
    ];

    /** Kennung des Bots → Name des Crawlers (erster Treffer gilt). */
    private const CRAWLERS = [
        '/Googlebot|Google-InspectionTool|Storebot-Google|GoogleOther/i' => 'Googlebot',
        '/bingbot|BingPreview|msnbot/i' => 'Bingbot',
        '/Applebot/i' => 'Applebot',
        '/DuckDuckBot/i' => 'DuckDuckBot',
        '/YandexBot|YandexImages/i' => 'YandexBot',
        '/Baiduspider/i' => 'Baiduspider',
        '/SeznamBot/i' => 'SeznamBot',
        '/PetalBot/i' => 'PetalBot',
        '/GPTBot|OAI-SearchBot|ChatGPT-User/i' => 'OpenAI',
        '/ClaudeBot|Claude-User|Claude-SearchBot/i' => 'Anthropic',
        '/PerplexityBot/i' => 'Perplexity',
        '/AhrefsBot/i' => 'AhrefsBot',
        '/SemrushBot/i' => 'SemrushBot',
        '/facebookexternalhit|meta-externalagent/i' => 'Facebook',
        '/Twitterbot/i' => 'X (Twitter)',
        '/LinkedInBot/i' => 'LinkedIn',
        '/WhatsApp/i' => 'WhatsApp',
    ];

    /** Name der Suchmaschine zu einem Host der Herkunft — oder null. */
    public static function engine(?string $host): ?string
    {
        if ($host === null || $host === '') {
            return null;
        }
        $host = strtolower($host);
        foreach (self::ENGINES as $pattern => $name) {
            if (preg_match($pattern, $host) === 1) {
                return $name;
            }
        }

        return null;
    }

    /** Name des Crawlers zu einer Kennung — «Anderer Bot», wenn er nicht bekannt ist. */
    public static function crawler(string $agent): string
    {
        foreach (self::CRAWLERS as $pattern => $name) {
            if (preg_match($pattern, $agent) === 1) {
                return $name;
            }
        }

        return 'Anderer Bot';
    }

    /**
     * Muster für SQL (`LIKE`) der Hosts, die als Suchmaschine zählen — grob vorgefiltert, genau prüft {@see engine()}.
     *
     * @return list<string>
     */
    public static function likePatterns(): array
    {
        return ['%google.%', '%bing.com', '%duckduckgo.com', '%ecosia.org', '%yahoo.%', '%yandex.%', '%startpage.com', '%qwant.com', '%search.brave.com',
            '%swisscows.%', '%baidu.com', '%seznam.cz', '%naver.com', '%perplexity.ai', '%chatgpt.com'];
    }
}
