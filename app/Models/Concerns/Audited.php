<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn (Model $model) => app(AuditLogger::class)->log(self::auditName($model).'.created', $model, ['before' => [], 'after' => self::safeAuditAttributes($model->getAttributes(), $model)]));
        static::updated(function (Model $model): void {
            $after = self::safeAuditAttributes($model->getChanges(), $model);
            $before = self::safeAuditAttributes(array_intersect_key($model->getRawOriginal(), $after), $model);
            if ($after !== []) {
                app(AuditLogger::class)->log(self::auditName($model).'.updated', $model, ['before' => $before, 'after' => $after]);
            }
        });
        static::deleted(fn (Model $model) => app(AuditLogger::class)->log(self::auditName($model).'.deleted', $model, ['before' => self::safeAuditAttributes($model->getRawOriginal(), $model), 'after' => []]));
    }

    private static function safeAuditAttributes(array $attributes, Model $model): array
    {
        return array_diff_key($attributes, array_flip(array_merge(['password', 'remember_token'], $model->getHidden())));
    }

    private static function auditName(Model $model): string
    {
        return strtolower(class_basename($model));
    }
}
