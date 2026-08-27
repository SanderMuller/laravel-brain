<?php

namespace App\Http\Resources;

use App\Models\Article;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
{
    public function toArray($request): array
    {
        if ($request->user()?->isEditor()) {
            return [
                'id' => $this->id,
                'title' => $this->title,
                'reviewer_notes' => $this->reviewer_notes,
            ];
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            Article::FIELD_SLUG => $this->slug,
            'author' => new AuthorResource($this->author),
            'tags' => $this->whenLoaded('tags'),
            ...$this->meta(),
        ];
    }
}
