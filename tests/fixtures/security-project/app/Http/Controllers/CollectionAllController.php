<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Reproduces Bug 1: `->all()` on a Collection / Eloquent result should NOT
 * be flagged as UNVALIDATED_INPUT — only `$request->all()` should.
 */
class CollectionAllController
{
    public function indexCollectionOnly(): array
    {
        return collect([1, 2, 3])->values()->all();
    }

    public function indexEloquentCollection(): array
    {
        return User::query()->get(['id'])->all();
    }

    public function storeWithRequestAll(Request $request): array
    {
        return $request->all();
    }

    /**
     * The classic real-world false negative we still want to catch: a
     * controller action with no FormRequest type-hint that pulls input via
     * the `request()` helper.
     */
    public function storeWithRequestHelper(): array
    {
        return request()->all();
    }
}
