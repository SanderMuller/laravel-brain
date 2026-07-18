<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // nested composition — resource -> resource
            'orders' => OrderResource::collection($this->orders),
            'latest' => new OrderResource($this->latestOrder),
        ];
    }
}
