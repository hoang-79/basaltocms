<?php

declare(strict_types=1);

namespace Basalto\Core\Seo;

use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Structure\Definition;

/**
 * Was das Theme im Block `publish` über die Auslieferung sagt (M-10): Sitemap an oder aus, `robots: allow|disallow`,
 * Umleitungen (`redirects { /alt -> /neu }`). Ohne lesbares Theme gelten die Vorgaben: Sitemap an, Robots erlaubt.
 */
final class PublishSettings
{
    public function __construct(private readonly ThemeLoader $themes) {}

    public function sitemapEnabled(Site $site): bool
    {
        return ! in_array($this->publish($site)?->value('sitemap'), ['false', 'off', 'aus', 'no'], true);
    }

    public function robotsAllowed(Site $site): bool
    {
        // TB 64: eine Testumgebung (BASALTO_NOINDEX) sperrt alles, gleich was das Theme sagt
        if ((bool) config('basalto.noindex', false)) {
            return false;
        }

        return ! in_array($this->publish($site)?->value('robots'), ['disallow', 'deny', 'none', 'false'], true);
    }

    /**
     * Umleitungen aus dem Theme: alter Pfad → Ziel. Sie gelten nach denen aus der Datenbank (die Redaktion kann sie überstimmen).
     *
     * @return array<string, string>
     */
    public function redirects(Site $site): array
    {
        $out = [];
        foreach ($this->publish($site)?->childrenOf('redirects') ?? [] as $block) {
            foreach ($block->childrenOf('redirect') as $redirect) {
                $target = $redirect->arrowTarget()?->text();
                $from = '/'.trim((string) $redirect->name, '/');
                if ($target !== null && $target !== '' && $from !== '/') {
                    $out[$from] = $target;
                }
            }
        }

        return $out;
    }

    private function publish(Site $site): ?Definition
    {
        try {
            return $this->themes->forSite($site)->document->find('publish');
        } catch (ThemeException) {
            return null;
        }
    }
}
