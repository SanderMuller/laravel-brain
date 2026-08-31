<?php

namespace Modules\Catalog\Filament\Resources;

use Modules\Catalog\Models\Product;

/**
 * Extends a base class in its own namespace, so PHP needs no `use` for it and nobody
 * writes one. The parent is written as a bare short name.
 */
class ProductResource extends CatalogResource
{
    protected static ?string $model = Product::class;
}
