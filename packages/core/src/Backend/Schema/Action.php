<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

/**
 * Ein Knopf einer Verwaltungsseite aus Beschreibung (CR-018, TB 99): Link oder Anfrage (POST, PUT, DELETE) an eine
 * Route des Plugins, auf Wunsch mit Rückfrage. Als Sammelaktion einer Liste schickt er `action` und die gewählten `ids`.
 */
final class Action
{
    /** @var array<string, mixed> */
    private array $data = [];

    private ?string $confirm = null;

    private string $variant = 'secondary';

    private function __construct(private readonly string $label, private readonly string $url, private readonly string $method, private readonly ?string $key = null) {}

    public static function link(string $label, string $url): self
    {
        return new self($label, $url, 'get');
    }

    public static function post(string $label, string $url): self
    {
        return new self($label, $url, 'post');
    }

    public static function put(string $label, string $url): self
    {
        return new self($label, $url, 'put');
    }

    public static function delete(string $label, string $url): self
    {
        return new self($label, $url, 'delete');
    }

    /** Sammelaktion einer Liste: POST an `$url` mit `action = $key` und `ids = [...]`. */
    public static function bulk(string $key, string $label, string $url): self
    {
        return new self($label, $url, 'post', $key);
    }

    /** Rückfrage vor dem Ausführen — der Text sagt, was geschieht. */
    public function confirm(string $question): self
    {
        $this->confirm = $question;

        return $this;
    }

    /** Hauptknopf (`primary`), normal (`secondary`) oder gefährlich (`danger`). */
    public function variant(string $variant): self
    {
        $this->variant = in_array($variant, ['primary', 'secondary', 'danger'], true) ? $variant : 'secondary';

        return $this;
    }

    /** @param  array<string, mixed>  $data  Daten, die mit der Anfrage gehen */
    public function with(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /** @return array{label: string, url: string, method: string, key: string|null, confirm: string|null, variant: string, data: array<string, mixed>} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'url' => $this->url, 'method' => $this->method, 'key' => $this->key, 'confirm' => $this->confirm, 'variant' => $this->variant, 'data' => $this->data];
    }
}
