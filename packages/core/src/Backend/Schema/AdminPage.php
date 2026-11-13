<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

use Illuminate\Contracts\Support\Responsable;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Eine Verwaltungsseite aus Beschreibung (CR-018, TB 99, E-3): Ein Plugin beschreibt in PHP, was die Seite zeigt —
 * Kennzahlen, Hinweise, Listen, Formulare, Knöpfe, Boards (CR-020) —, und gibt sie aus seinem Controller zurück; der Kern zeichnet sie mit
 * seinen eigenen Komponenten. So braucht ein Plugin kein eigenes JavaScript im Backend, und seine Seiten sehen aus,
 * speichern und verhalten sich wie die des Kerns (Autosave, Tastatur, vier Sprachen). Texte übergibt das Plugin schon
 * übersetzt (`__()` mit seinen eigenen Sprachdateien).
 *
 * ```php
 * return AdminPage::make($title)->crumb($crumb)
 *     ->stats([Stat::make($label, 12)])
 *     ->table(Table::make()->columns([...])->rows($page->items(), fn ($r) => Row::make($r->id)->cell('name', $r->name)));
 * ```
 */
final class AdminPage implements Responsable
{
    private string $crumb = '';

    private string $lead = '';

    /** @var list<Action> */
    private array $actions = [];

    /** @var list<array<string, mixed>> */
    private array $sections = [];

    private function __construct(private readonly string $title) {}

    public static function make(string $title): self
    {
        return new self($title);
    }

    /** Pfad über dem Titel, z. B. «Inhalte › Reservierungen». */
    public function crumb(string $crumb): self
    {
        $this->crumb = $crumb;

        return $this;
    }

    public function lead(string $lead): self
    {
        $this->lead = $lead;

        return $this;
    }

    /** Knöpfe oben rechts. */
    public function action(Action $action): self
    {
        $this->actions[] = $action;

        return $this;
    }

    /** @param  list<Stat>  $stats */
    public function stats(array $stats): self
    {
        $this->sections[] = ['kind' => 'stats', 'items' => array_map(static fn (Stat $s): array => $s->toArray(), $stats)];

        return $this;
    }

    /** Ein Hinweis: `grey`, `success`, `warning` oder `danger`. */
    public function notice(string $text, string $tone = 'grey', ?string $title = null): self
    {
        $this->sections[] = ['kind' => 'notice', 'tone' => in_array($tone, ['grey', 'success', 'warning', 'danger'], true) ? $tone : 'grey', 'title' => $title, 'text' => $text];

        return $this;
    }

    /** Ein Absatz Text (ohne HTML). */
    public function text(string $text, ?string $title = null): self
    {
        $this->sections[] = ['kind' => 'text', 'title' => $title, 'text' => $text];

        return $this;
    }

    public function table(Table $table): self
    {
        $this->sections[] = $table->toArray();

        return $this;
    }

    public function form(Form $form): self
    {
        $this->sections[] = $form->toArray();

        return $this;
    }

    /** Ein Board mit Spalten und Karten zum Verschieben (CR-020). */
    public function board(Board $board): self
    {
        $this->sections[] = $board->toArray();

        return $this;
    }

    /** @return array{title: string, crumb: string, lead: string, actions: list<array<string, mixed>>, sections: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'crumb' => $this->crumb, 'lead' => $this->lead, 'actions' => array_map(static fn (Action $a): array => $a->toArray(), $this->actions), 'sections' => $this->sections];
    }

    public function toResponse($request): Response
    {
        return Inertia::render('core::AdminPage', ['page' => $this->toArray()])->toResponse($request);
    }
}
