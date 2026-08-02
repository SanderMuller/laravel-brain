<?php

namespace App\Models;

use App\Observers\ProductAuditObserver;
use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy([ProductObserver::class, ProductAuditObserver::class])]
class Product extends Model
{
    protected $fillable = ['name'];
}
