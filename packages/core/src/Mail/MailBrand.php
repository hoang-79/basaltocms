<?php

declare(strict_types=1);

namespace Basalto\Core\Mail;

use Basalto\Core\Content\Pages;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Theme\ThemeAssets;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\AnonymousNotifiable;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Absender einer Mail im Layout (CR-011, TB 58): Name und Adresse der Site, zu der die Mail gehört, die Zeilen des
 * Anbieters aus dem globalen Fuss der Site (Angebot, Inhaber, Strasse, Ort, Land, MWST — oder eine Adresse) und die
 * Links auf Impressum und Datenschutz aus `publish.legal` des Themes. Die Site kommt vor dem Versand aus dem Empfänger
 * (`NotificationSending`: Kundenkonten tragen ihre Site); ohne Site die Site des Herstellers bzw. die erste Site der
 * Installation; gibt es keine, der Name der App ohne Zeilen.
 *
 * Im Kopf steht das Logo der Site (TB 71) — als PNG, denn Gmail und Outlook zeigen kein SVG: ein Raster-Logo aus der
 * Mediathek oder neben dem Logo des Themes dieselbe Datei als `.png` (`assets/logo.svg` → `assets/logo.png`). Ein breites
 * Logo (Wortmarke) steht allein, ein Zeichen mit dem Namen daneben; ohne PNG bleibt der Name. Das PNG des Themes liegt
 * in dreifacher Grösse vor und trägt bei dunklem Logo eine helle Fläche in der Farbe des Mailgrunds — die Gmail-App und
 * Outlook färben im Dunkelmodus die Mail dunkel, Bilder aber nicht (`tools/mail-logo.py`).
 *
 * @phpstan-type Logo array{url: string, width: int, height: int, wordmark: bool}
 * @phpstan-type Brand array{name: string, url: string, logo: Logo|null, lines: list<string>, links: list<array{label: string, url: string}>, locale: string}
 */
final class MailBrand
{
    /** Felder des globalen Fusses, die als Zeilen des Anbieters gelten — in dieser Reihenfolge. */
    private const LINES = ['brand', 'owner', 'street', 'city', 'country', 'vat', 'address'];

    /** Bildformate, die alle verbreiteten Mailprogramme zeigen. */
    private const RASTER = ['image/png', 'image/jpeg', 'image/gif'];

    /** Höhe eines Logos aus der Mediathek im Kopf in Pixeln: Wortmarke, Zeichen. */
    private const HEIGHT_WORDMARK = 28;

    private const HEIGHT_SIGN = 36;

    /** Das PNG des Themes trägt drei Pixel je Bildpunkt; gezeigt zwischen diesen Höhen. */
    private const THEME_DENSITY = 3;

    private const THEME_HEIGHT = [16, 64];

    private ?Site $site = null;

    private ?string $locale = null;

    public function __construct(private readonly Pages $pages, private readonly ThemeLoader $themes, private readonly ThemeAssets $assets) {}

    /** Vor dem Versand einer Benachrichtigung: die Site des Empfängers, wenn er eine hat. */
    public function useFor(object $notifiable): void
    {
        // Mails an eine Adresse ohne Konto (`Notification::route('mail', …)`) nennen ihre Site mit `->route('site', id)`
        $siteId = $notifiable instanceof AnonymousNotifiable ? ($notifiable->routes['site'] ?? null)
            : (method_exists($notifiable, 'getAttribute') ? $notifiable->getAttribute('site_id') : null);
        $this->site = is_numeric($siteId) ? Site::query()->find((int) $siteId) : null;
        $this->locale = $notifiable instanceof HasLocalePreference ? $notifiable->preferredLocale() : null;
    }

    public function useSite(?Site $site, ?string $locale = null): void
    {
        $this->site = $site;
        $this->locale = $locale;
    }

    public function reset(): void
    {
        $this->site = null;
        $this->locale = null;
    }

    /** @return Brand */
    public function current(): array
    {
        $locale = $this->locale ?? app()->getLocale();
        $site = $this->site ?? $this->fallback();
        if ($site === null) {
            return ['name' => (string) config('app.name', 'Basalto'), 'url' => (string) config('app.url'), 'logo' => null, 'lines' => [], 'links' => [], 'locale' => $locale];
        }
        $locale = in_array($locale, $site->localeCodes(), true) ? $locale : $site->default_locale;
        try {
            return ['name' => $site->name, 'url' => $site->publicUrl(), 'logo' => $this->logo($site), 'lines' => $this->lines($site, $locale), 'links' => $this->links($site, $locale), 'locale' => $locale];
        } catch (Throwable) {
            return ['name' => $site->name, 'url' => (string) config('app.url'), 'logo' => null, 'lines' => [], 'links' => [], 'locale' => $locale];
        }
    }

    private function fallback(): ?Site
    {
        try {
            return Extranet::vendorSite();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return Logo|null */
    private function logo(Site $site): ?array
    {
        $mediaId = ThemeAssets::overrides($site)['logo_media_id'] ?? null;
        if ($mediaId !== null) {
            // Logo aus der Mediathek: nur ein Raster-Original — SVG und WebP zeigen nicht alle Mailprogramme
            $media = Media::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereKey($mediaId)->first();

            return $media !== null && in_array($media->mime, self::RASTER, true) && $media->width > 0 && $media->height > 0
                ? self::sized($site->publicUrl(route('basalto.media.show', ['media' => $media->id], false)), $media->width, $media->height) : null;
        }
        $logo = $this->assets->design($site)->logo;
        if ($logo === null || ! str_starts_with($logo, 'assets/')) {
            return null;
        }
        $png = in_array(strtolower(pathinfo($logo, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif'], true) ? $logo : (string) preg_replace('/\.[a-z0-9]+$/i', '.png', $logo);
        $content = $this->assets->read($site, $png);
        $size = $content === null ? false : @getimagesizefromstring($content);
        $url = $this->assets->url($site, $png);
        if ($size === false || $url === null || ! in_array($size['mime'], self::RASTER, true) || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        $shown = max(self::THEME_HEIGHT[0], min(self::THEME_HEIGHT[1], (int) round($size[1] / self::THEME_DENSITY)));

        return self::sized($site->publicUrl($url), $size[0], $size[1], $shown);
    }

    /** @return Logo */
    private static function sized(string $url, int $width, int $height, ?int $shown = null): array
    {
        $wordmark = $width / $height >= 2;
        $shown ??= $wordmark ? self::HEIGHT_WORDMARK : self::HEIGHT_SIGN;

        return ['url' => $url, 'width' => (int) round($shown * $width / $height), 'height' => $shown, 'wordmark' => $wordmark];
    }

    /** @return list<string> */
    private function lines(Site $site, string $locale): array
    {
        $global = Entry::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('type', 'footer')->whereNull('deleted_at')->orderBy('id')->first();
        $data = $global?->resolve($locale)->data ?? [];
        $lines = [];
        foreach (self::LINES as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $lines[] = trim($value);
            }
        }

        return $lines;
    }

    /** @return list<array{label: string, url: string}> */
    private function links(Site $site, string $locale): array
    {
        $legal = $this->themes->forSite($site)->document->find('publish')?->childrenOf('legal')[0] ?? null;
        $links = [];
        foreach ($legal === null ? [] : $legal->properties as $property) {
            $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('basalto-seed:%d:page:%s', $site->id, $property->text()))->toString();
            $entry = Entry::query()->withoutGlobalScopes()->where('uuid', $uuid)->whereNull('deleted_at')->first();
            $path = $entry === null ? null : $this->pages->urlOf($entry, $locale);
            $url = $path === null ? null : $site->publicUrl($path);
            $title = $entry?->resolve($locale)?->data['title'] ?? null;
            if (is_string($url) && is_string($title)) {
                $links[] = ['label' => $title, 'url' => $url];
            }
        }

        return $links;
    }
}
