<?php

declare(strict_types=1);

namespace Basalto\Core\Seo;

use DateTimeInterface;

/**
 * Beiträge zum Kopf der Seite in Arbeit (CR-019, TB 106): Lava-Teile, Tags und Module, die erst beim Rendern wissen,
 * was sie zeigen (ein Eintrag im Marktplatz über `?paket=`), setzen hier Titel, Beschreibung, Bild, Art, die Parameter
 * der kanonischen Adresse und strukturierte Daten. {@see SeoHead} liest sie, wenn die Seite fertig ist. Gilt je Anfrage
 * (`scoped`); der Renderer setzt sie vor jeder Seite zurück.
 *
 * @phpstan-type Image array{src: string, width: int|null, height: int|null, alt: string}
 */
final class PageSeo
{
    public ?string $title = null;

    public ?string $description = null;

    /** @var Image|null */
    public ?array $image = null;

    /** «website», «article», «product» … — sonst entscheidet der Kopf selbst. */
    public ?string $type = null;

    /** @var array<string, string> Parameter, die zur Adresse dieser Ansicht gehören (kanonisch, hreflang, og:url) */
    public array $query = [];

    /** @var list<array<string, mixed>> zusätzliche Knoten für das JSON-LD der Seite */
    public array $data = [];

    public bool $noindex = false;

    public ?DateTimeInterface $published = null;

    public ?DateTimeInterface $modified = null;

    public function reset(): void
    {
        $this->title = $this->description = $this->type = null;
        $this->image = null;
        $this->query = $this->data = [];
        $this->noindex = false;
        $this->published = $this->modified = null;
    }

    public function title(string $title): self
    {
        $this->title = trim($title) === '' ? null : trim($title);

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = trim($description) === '' ? null : trim($description);

        return $this;
    }

    /** Volle Adresse oder Pfad der Site; Grösse in Pixeln, wenn bekannt. */
    public function image(string $src, ?int $width = null, ?int $height = null, string $alt = ''): self
    {
        $this->image = $src === '' ? null : ['src' => $src, 'width' => $width, 'height' => $height, 'alt' => $alt];

        return $this;
    }

    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Die Ansicht hängt an Parametern der Adresse (`?paket=basalto/events`): Sie gehören dann zur kanonischen Adresse
     * und zu den Sprachfassungen — sonst hielte eine Suchmaschine alle Ansichten für dieselbe Seite.
     *
     * @param  array<string, string>  $params
     */
    public function query(array $params): self
    {
        $this->query = array_filter($params, static fn (string $v): bool => $v !== '');
        ksort($this->query);

        return $this;
    }

    /** @param  array<string, mixed>  $node  ein Knoten mit «@type» */
    public function data(array $node): self
    {
        $this->data[] = $node;

        return $this;
    }

    public function noindex(): self
    {
        $this->noindex = true;

        return $this;
    }

    public function dates(?DateTimeInterface $published, ?DateTimeInterface $modified = null): self
    {
        $this->published = $published;
        $this->modified = $modified;

        return $this;
    }
}
