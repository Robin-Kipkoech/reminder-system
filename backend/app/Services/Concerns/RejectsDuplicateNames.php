<?php

namespace App\Services\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

trait RejectsDuplicateNames
{
    /**
     * Soft-deleted rows keep their unique name, so the database is the final guard
     * when a name is reused after a delete. Surface that as a 422 instead of a 500.
     *
     * @param  callable(): mixed  $operation
     */
    private function guardUniqueName(callable $operation, string $field = 'name'): mixed
    {
        try {
            return $operation();
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    $field => [sprintf('The %s has already been taken.', $field)],
                ]);
            }

            throw $exception;
        }
    }

    private function isUniqueViolation(Throwable $exception): bool
    {
        return ($exception instanceof QueryException)
            && ($exception->getCode() === '23000' || str_contains($exception->getMessage(), 'Integrity constraint violation'));
    }
}
