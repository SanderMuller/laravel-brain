<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            // recursive self-composition — must not create a self-loop edge
            'children' => OrderResource::collection($this->children),
            // keyword self-reference — must not resolve to a phantom \Http\Resources\static
            'parent' => new static($this->parent),
        ];
    }
}
