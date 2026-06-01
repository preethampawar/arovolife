<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product-level descriptive attribute (ingredients, nutritional information,
 * storage, caution, directions, …). The value is sanitised WYSIWYG HTML so it
 * can hold a formatted table or an inline image (e.g. a nutritional-facts
 * table). Rows render on the product detail page ordered by {@see $sort}.
 */
final class ProductAttribute extends Model
{
    protected $table = 'product_attributes';

    protected $fillable = [
        'product_id', 'label', 'value_html', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'int',
            'sort' => 'int',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
