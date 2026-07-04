<?php

namespace App\Policies\Custom;

class ArticleAccessPolicy
{
    public function view($authUser, $article): bool
    {
        return true;
    }
}
