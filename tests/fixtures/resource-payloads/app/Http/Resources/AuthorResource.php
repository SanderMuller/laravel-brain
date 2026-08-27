<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuthorResource extends JsonResource
{
    // A payload this cannot enumerate: the parent builds it, and nothing here says what is in it.
    public function toArray($request): array
    {
        return parent::toArray($request);
    }
}
