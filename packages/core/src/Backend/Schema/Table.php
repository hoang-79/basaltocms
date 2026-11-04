<?php

declare(strict_types=1);

namespace Basalto\Core\Backend\Schema;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eine Liste auf einer Verwaltungsseite (CR-018, TB 99): Spalten, Zeilen, Suche, Filter, Seiten und Sammelaktionen.
 * Suche, Filter und Seite stehen in der Adresse (`?q=…&status=…&page=…`) — der Controller des Plugins liest sie und
 * fragt die Datenbank selbst ab; die Liste zeigt nur, was er schickt.
 */
final class Table
{
    private ?string $title = null;

    /** @var list<Column> */
    private array $columns = [];

    /** @var list<Row> */
    private array $rows = [];

    /** @var array{param: string, value: string, placeholder: string}|null */
    private ?array $search = null;

    /** @var list<Filter> */
    private array $filters = [];

    /** @var array{page: int, last: int, total: int, per_page: int}|null */
    private ?array $pagination = null;

    /** @var list<Action> */
    private array $bulk = [];

    /** @var array{title: string, text: string|null} */
    private array $empty = ['title' => '', 'text' => null];

    private function __construct(private readonly string $key) {}

    /** `$key` unterscheidet mehrere Listen auf einer Seite. */
    public static function make(string $key = 'liste'): self
    {
        return new self($key);
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /** @param  list<Column>  $columns */
    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  callable(T): Row  $row
     */
    public function rows(iterable $items, callable $row): self
    {
        foreach ($items as $item) {
            $this->rows[] = $row($item);
        }

        return $this;
    }

    public function search(?string $value, string $placeholder = '', string $param = 'q'): self
    {
        $this->search = ['param' => $param, 'value' => (string) $value, 'placeholder' => $placeholder];

        return $this;
    }

    public function filter(Filter $filter): self
    {
        $this->filters[] = $filter;

        return $this;
    }

    /** @param  LengthAwarePaginator<int, mixed>  $paginator */
    public function paginate(LengthAwarePaginator $paginator): self
    {
        $this->pagination = ['page' => $paginator->currentPage(), 'last' => $paginator->lastPage(), 'total' => $paginator->total(), 'per_page' => $paginator->perPage()];

        return $this;
    }

    /** Sammelaktionen — mit ihnen bekommt die Liste Häkchen (siehe `Action::bulk`). */
    public function bulk(Action ...$actions): self
    {
        $this->bulk = array_values([...$this->bulk, ...$actions]);

        return $this;
    }

    /** Was die leere Liste sagt. */
    public function empty(string $title, ?string $text = null): self
    {
        $this->empty = ['title' => $title, 'text' => $text];

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => 'table', 'key' => $this->key, 'title' => $this->title,
            'columns' => array_map(static fn (Column $c): array => $c->toArray(), $this->columns),
            'rows' => array_map(static fn (Row $r): array => $r->toArray(), $this->rows),
            'search' => $this->search,
            'filters' => array_map(static fn (Filter $f): array => $f->toArray(), $this->filters),
            'pagination' => $this->pagination,
            'bulk' => array_map(static fn (Action $a): array => $a->toArray(), $this->bulk),
            'empty' => $this->empty,
        ];
    }
}
