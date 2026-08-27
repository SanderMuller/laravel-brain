<?php

namespace Modules\Shop\Filament\Support;

use Filament\Resources\Resource;

/**
 * A project base class sitting between Filament and every real resource — the
 * shape that makes a single-level `extends Resource` check find nothing.
 */
abstract class ShopResource extends Resource {}
