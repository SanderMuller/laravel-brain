<?php

namespace App\Models;

use App\Policies\Custom\ArticleAccessPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

#[UsePolicy(ArticleAccessPolicy::class)]
class Article extends Model
{
    protected $fillable = ['title'];
}
