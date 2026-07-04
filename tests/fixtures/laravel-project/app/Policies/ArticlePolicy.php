<?php

namespace App\Policies;

class ArticlePolicy
{
    public function view($authUser, $article): bool
    {
        return true;
    }
}
