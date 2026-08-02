<?php

namespace App\Models;

use App\Observers\TagObserver as Watcher;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(Watcher::class)]
class Tag extends Model
{
    protected $fillable = ['name'];
}
