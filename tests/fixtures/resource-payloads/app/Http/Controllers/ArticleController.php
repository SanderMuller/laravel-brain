<?php

namespace App\Http\Controllers;

use App\Http\Resources\ArticleResource;

class ArticleController
{
    public function show($article)
    {
        return new ArticleResource($article);
    }
}
