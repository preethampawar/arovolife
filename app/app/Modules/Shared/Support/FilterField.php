<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * One control on a list page's filter toolbar.
 *
 * A field is a description, not behaviour: it says what the control looks
 * like, which request key it reads, and — optionally — which column it maps
 * onto so {@see ListFilters::apply()} can apply it without the controller
 * writing a `where`. A field that names no column is still parsed, validated,
 * rendered and chipped; only the query clause is left to the caller, which is
 * what every page with a join, a raw expression or a service-built query needs.
 *
 * Construction is through the named factories, never `new`: the type string
 * and the per-type invariants (a select has options, a date range has two
 * request keys) are only guaranteed if you go through them.
 *
 * @see ListFilters
 * @see \resources\views\components\filter-bar.blade.php
 */
final class FilterField
{
    public const TYPE_TEXT = 'text';

    public const TYPE_SELECT = 'select';

    public const TYPE_DATE_RANGE = 'date_range';

    public const TYPE_MONTH = 'month';

    public const TYPE_BOOLEAN = 'bool';

    /**
     * @param  array<string, string>  $options  value => label, select only
     * @param  list<string>  $columns  LIKE targets, text only
     */
    private function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
        public readonly array $options = [],
        public readonly ?string $placeholder = null,
        public readonly ?string $column = null,
        public readonly array $columns = [],
        public readonly ?string $dateColumn = null,
        public readonly string $fromKey = '',
        public readonly string $toKey = '',
    ) {}

    /**
     * A free-text search box.
     *
     * @param  list<string>  $columns  columns to LIKE against; empty means the
     *                                 controller applies the value itself
     */
    public static function text(
        string $key,
        string $label,
        ?string $placeholder = null,
        array $columns = [],
    ): self {
        return new self(
            key: $key,
            type: self::TYPE_TEXT,
            label: $label,
            placeholder: $placeholder,
            columns: $columns,
        );
    }

    /**
     * A single-choice dropdown. A submitted value outside `$options` is
     * discarded by {@see ListFilters::make()} and never reaches the query.
     *
     * @param  array<string, string>  $options  value => label
     */
    public static function select(
        string $key,
        string $label,
        array $options,
        ?string $column = null,
        string $placeholder = 'All',
    ): self {
        return new self(
            key: $key,
            type: self::TYPE_SELECT,
            label: $label,
            options: $options,
            placeholder: $placeholder,
            column: $column,
        );
    }

    /** A pair of date inputs, read from `{$key}_from` and `{$key}_to`. */
    public static function dateRange(
        string $key,
        string $label,
        ?string $dateColumn = null,
        ?string $fromKey = null,
        ?string $toKey = null,
    ): self {
        return new self(
            key: $key,
            type: self::TYPE_DATE_RANGE,
            label: $label,
            dateColumn: $dateColumn,
            fromKey: $fromKey ?? $key.'_from',
            toKey: $toKey ?? $key.'_to',
        );
    }

    /** A `<input type="month">`, submitted and stored as `Y-m`. */
    public static function month(string $key, string $label, ?string $column = null): self
    {
        return new self(
            key: $key,
            type: self::TYPE_MONTH,
            label: $label,
            column: $column,
        );
    }

    /** A checkbox. Present and truthy means `1`; absent means no clause at all. */
    public static function boolean(string $key, string $label, ?string $column = null): self
    {
        return new self(
            key: $key,
            type: self::TYPE_BOOLEAN,
            label: $label,
            column: $column,
        );
    }

    /**
     * Every request key this field owns — the keys stripped by Clear, and the
     * keys a chip may remove. A date range owns two; everything else owns one.
     *
     * @return list<string>
     */
    public function requestKeys(): array
    {
        return $this->type === self::TYPE_DATE_RANGE
            ? [$this->fromKey, $this->toKey]
            : [$this->key];
    }
}
