<?php

namespace Acme\Shipping\Facades;

/**
 * Reaches Illuminate's Facade only through a base class that lives in ANOTHER
 * package: the parent chain has to be followed across the configured directories,
 * not just inside one of them.
 */
class Carrier extends AbstractCarrierFacade
{
    protected static function getFacadeAccessor(): string
    {
        return 'carrier';
    }
}
