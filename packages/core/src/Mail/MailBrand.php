<?php

declare(strict_types=1);

namespace Basalto\Core\Mail;

use Basalto\Core\Content\Pages;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Site;
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
 * @phpstan-type Brand array{name: string, url: string, lines: list<string>, links: list<array{label: string, url: string}>, locale: string}
 */
final class MailBrand
{
    /** Felder des globalen Fusses, die als Zeilen des Anbieters gelten — in dieser Reihenfolge. */
    private const LINES = ['brand', 'owner', 'street', 'city', 'country', 'vat', 'address'];

    private ?Site $site = null;

    private ?string $locale = null;

    public function __construct(private readonly Pages $pages, private readonly ThemeLoader $themes) {}

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
            return ['name' => (string) config('app.name', 'Basalto'), 'url' => (string) config('app.url'), 'lines' => [], 'links' => [], 'locale' => $locale];
        }
        $locale = in_array($locale, $site->localeCodes(), true) ? $locale : $site->default_locale;
        try {
            return ['name' => $site->name, 'url' => $site->publicUrl(), 'lines' => $this->lines($site, $locale), 'links' => $this->links($site, $locale), 'locale' => $locale];
        } catch (Throwable) {
            return ['name' => $site->name, 'url' => (string) config('app.url'), 'lines' => [], 'links' => [], 'locale' => $locale];
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
