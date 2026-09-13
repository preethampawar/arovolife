<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * The active filter state of one list page.
 *
 * Built in the controller from the request and a list of {@see FilterField}s,
 * handed to the query (via {@see self::apply()} or by reading values directly)
 * and to `<x-filter-bar>`, which renders the toolbar from the same object. One
 * declaration therefore drives the control, the clause, the chip, the Clear
 * link, the paginator's `appends()` and the export link — the four places that
 * used to be hand-maintained per page and drift apart.
 *
 * **Values are validated on the way in, not on the way out.** A select value
 * that is not one of its own options, a date that is not `Y-m-d`, a month that
 * is not `Y-m` — all are discarded by {@see self::make()}. Nothing downstream
 * has to re-check, and a hand-edited URL cannot push an unexpected value into
 * a `where`.
 *
 * **This object narrows; it never widens.** It is applied to a query that has
 * already been scoped (by role, by ownership, by feature flag). On
 * distributor-facing pages the scoping clause must precede `apply()` and must
 * never be derived from a filter value — see CLAUDE.md hard rule 3.
 */
final class ListFilters
{
    /**
     * @param  list<FilterField>  $fields
     * @param  array<string, string>  $values  request key => validated value
     * @param  array<string, string>  $carried  non-filter query params to preserve
     */
    private function __construct(
        public readonly array $fields,
        public readonly array $values,
        private readonly string $path,
        private readonly array $carried,
    ) {}

    /**
     * Read the request through the given field list.
     *
     * @param  list<FilterField>  $fields
     */
    public static function make(Request $request, array $fields): self
    {
        $values = [];
        $owned = [];

        foreach ($fields as $field) {
            foreach ($field->requestKeys() as $key) {
                $owned[] = $key;
                $raw = $request->query($key);

                if (! is_string($raw) && ! is_int($raw)) {
                    continue;
                }

                $value = self::sanitise($field, (string) $raw);

                if ($value !== null) {
                    $values[$key] = $value;
                }
            }
        }

        $carried = [];

        foreach ($request->query() as $key => $value) {
            if (in_array($key, $owned, true) || $key === 'page' || ! is_string($value)) {
                continue;
            }

            $carried[$key] = $value;
        }

        return new self($fields, $values, $request->url(), $carried);
    }

    /**
     * Normalise one raw request value, or null to discard it.
     *
     * Discarding rather than erroring is deliberate: a stale bookmark or a
     * mistyped URL should show the unfiltered list, not a validation page.
     */
    private static function sanitise(FilterField $field, string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        return match ($field->type) {
            FilterField::TYPE_SELECT => array_key_exists($value, $field->options) ? $value : null,
            FilterField::TYPE_DATE_RANGE => self::matchesDate($value, 'Y-m-d') ? $value : null,
            FilterField::TYPE_MONTH => self::matchesDate($value, 'Y-m') ? $value : null,
            FilterField::TYPE_BOOLEAN => in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true) ? '1' : null,
            default => $value,
        };
    }

    private static function matchesDate(string $value, string $format): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $value);

        return $parsed !== false && $parsed->format($format) === $value;
    }

    public function value(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function any(): bool
    {
        return $this->values !== [];
    }

    public function field(string $key): ?FilterField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The active filter values, for `appends()` on a paginator and for export
     * links. Never includes `page`.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return $this->values;
    }

    /**
     * Apply every field that declares a column mapping.
     *
     * Fields with no mapping are skipped — read those with {@see self::value()}
     * and apply them yourself. Returns the same builder for chaining.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(Builder $query): Builder
    {
        foreach ($this->fields as $field) {
            match ($field->type) {
                FilterField::TYPE_TEXT => $this->applyText($query, $field),
                FilterField::TYPE_DATE_RANGE => $this->applyDateRange($query, $field),
                default => $this->applyEquals($query, $field),
            };
        }

        return $query;
    }

    private function applyText(Builder $query, FilterField $field): void
    {
        $value = $this->value($field->key);

        if ($value === null || $field->columns === []) {
            return;
        }

        $term = '%'.self::escapeLike($value).'%';

        $query->where(function (Builder $inner) use ($field, $term): void {
            foreach ($field->columns as $column) {
                $inner->orWhere($column, 'like', $term);
            }
        });
    }

    private function applyDateRange(Builder $query, FilterField $field): void
    {
        if ($field->dateColumn === null) {
            return;
        }

        if (($from = $this->value($field->fromKey)) !== null) {
            $query->whereDate($field->dateColumn, '>=', $from);
        }

        if (($to = $this->value($field->toKey)) !== null) {
            $query->whereDate($field->dateColumn, '<=', $to);
        }
    }

    private function applyEquals(Builder $query, FilterField $field): void
    {
        $value = $this->value($field->key);

        if ($value === null || $field->column === null) {
            return;
        }

        $query->where($field->column, $value);
    }

    /**
     * Escape the LIKE wildcards so a search for "50%" does not match everything.
     * Pairs with the default backslash escape character on both MySQL and SQLite.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * One chip per active value, each linking to the current page with that
     * one value removed. `page` is always dropped: narrowing or widening a
     * filter must land the viewer back on page 1, never on a page number that
     * no longer exists.
     *
     * @return list<array{key: string, label: string, display: string, url: string}>
     */
    public function chips(): array
    {
        $chips = [];

        foreach ($this->fields as $field) {
            foreach ($field->requestKeys() as $key) {
                if (! $this->has($key)) {
                    continue;
                }

                $chips[] = [
                    'key' => $key,
                    'label' => $this->chipLabel($field, $key),
                    'display' => $this->chipDisplay($field, $key),
                    'url' => $this->urlWithout([$key]),
                ];
            }
        }

        return $chips;
    }

    private function chipLabel(FilterField $field, string $key): string
    {
        if ($field->type !== FilterField::TYPE_DATE_RANGE) {
            return $field->label;
        }

        return $key === $field->fromKey
            ? $field->label.' from'
            : $field->label.' to';
    }

    private function chipDisplay(FilterField $field, string $key): string
    {
        $value = (string) $this->value($key);

        return match ($field->type) {
            FilterField::TYPE_SELECT => $field->options[$value] ?? $value,
            FilterField::TYPE_BOOLEAN => 'Yes',
            default => $value,
        };
    }

    /** The current page with every filter value removed, other params kept. */
    public function clearUrl(): string
    {
        return $this->path.($this->carried === [] ? '' : '?'.http_build_query($this->carried));
    }

    /**
     * @param  list<string>  $keys
     */
    private function urlWithout(array $keys): string
    {
        $query = array_merge($this->carried, $this->values);

        foreach ($keys as $key) {
            unset($query[$key]);
        }

        return $this->path.($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * Query params the toolbar must re-emit as hidden inputs so that pressing
     * Filter does not drop a tab selection or any other non-filter state.
     *
     * @return array<string, string>
     */
    public function carriedParams(): array
    {
        return $this->carried;
    }
}
