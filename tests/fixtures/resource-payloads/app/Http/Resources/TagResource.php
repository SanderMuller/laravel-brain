<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TagResource extends JsonResource
{
    // Built up over several statements, which is a payload the reader has to open the file for.
    public function toArray($request): array
    {
        $payload = ['id' => $this->id];

        if ($this->slug) {
            $payload['slug'] = $this->slug;
        }

        return $payload;
    }
}
