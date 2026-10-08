<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailIntakeRejection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
