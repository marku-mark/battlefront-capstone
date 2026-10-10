<?php

namespace App\Actions\Category;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteCategory
{
    public const PROTECTED_MESSAGE = 'This category cannot be deleted because it contains products. Move or remove its products first, or deactivate the category.';

    public function execute(Category $category): void
    {
        try {
            DB::transaction(function () use ($category): void {
                $lockedCategory = Category::query()->lockForUpdate()->findOrFail($category->id);
                if ($lockedCategory->products()->exists()) {
                    throw ValidationException::withMessages(['deletion' => self::PROTECTED_MESSAGE]);
                }

                $lockedCategory->delete();
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1451
                || str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed')) {
                throw ValidationException::withMessages(['deletion' => self::PROTECTED_MESSAGE]);
            }

            throw $exception;
        }
    }
}
