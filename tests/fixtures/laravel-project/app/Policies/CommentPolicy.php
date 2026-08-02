<?php

namespace App\Policies;

class CommentPolicy
{
    public function update($authUser, $comment): bool
    {
        return true;
    }
}
