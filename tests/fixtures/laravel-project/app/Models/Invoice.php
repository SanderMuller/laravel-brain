<?php

namespace App\Models;

use App\Observers\InvoiceObserver;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = ['total'];

    protected static function booted(): void
    {
        static::observe(InvoiceObserver::class);
    }
}
