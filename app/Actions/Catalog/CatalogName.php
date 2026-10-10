<?php

namespace App\Actions\Catalog;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogName
{
    public const PRODUCT_MESSAGE = 'A product with this name already exists. Edit the existing product or use a different name.';

    public const CATEGORY_MESSAGE = 'A category with this name already exists.';

    public static function normalize(string $name): string
    {
        return Str::squish($name);
    }

    public static function key(string $name): string
    {
        return hash('sha256', Str::lower(self::normalize($name)));
    }

    public static function uniqueRule(Model $model, ?Model $ignore, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $ignore, $message): void {
            $query = $model->newQuery()->where('name_key', self::key($value));
            if ($ignore !== null) {
                $query->whereKeyNot($ignore->getKey());
            }
            if ($query->exists()) {
                $fail($message);
            }
        };
    }

    public static function handleUniqueFailure(UniqueConstraintViolationException $exception, string $table, string $message): never
    {
        if (str_contains($exception->getMessage(), $table.'_name_key_unique')
            || str_contains($exception->getMessage(), $table.'.name_key')
            || ($table === 'categories' && str_contains($exception->getMessage(), 'categories_name_unique'))) {
            throw ValidationException::withMessages(['name' => $message]);
        }

        if ($table === 'products' && (str_contains($exception->getMessage(), 'products_product_code_unique')
            || str_contains($exception->getMessage(), 'products.product_code'))) {
            throw ValidationException::withMessages(['product_code' => 'This product code is already in use.']);
        }

        throw $exception;
    }
}
