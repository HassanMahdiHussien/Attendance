<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;

trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new ImmutableRecordException('Append-only records cannot be updated.'));
        static::deleting(fn () => throw new ImmutableRecordException('Append-only records cannot be deleted.'));
    }
}
