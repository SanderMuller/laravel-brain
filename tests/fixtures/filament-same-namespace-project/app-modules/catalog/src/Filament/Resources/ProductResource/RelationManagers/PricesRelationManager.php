<?php

namespace Modules\Catalog\Filament\Resources\ProductResource\RelationManagers;

/** Same shape as ProductResource: base class in the same namespace, no import. */
class PricesRelationManager extends CatalogRelationManager
{
    protected static string $relationship = 'prices';
}
