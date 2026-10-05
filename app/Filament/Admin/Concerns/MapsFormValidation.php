<?php

namespace App\Filament\Admin\Concerns;

use Illuminate\Validation\ValidationException;

trait MapsFormValidation
{
    protected function rethrowFormValidation(ValidationException $exception, array $aliases = []): never
    {
        $messages = [];

        foreach ($exception->errors() as $field => $errors) {
            $field = $aliases[$field] ?? $field;
            $messages[str_starts_with($field, 'data.') ? $field : 'data.'.$field] = $errors;
        }

        throw ValidationException::withMessages($messages);
    }
}
