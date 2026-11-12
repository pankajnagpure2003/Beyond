<?php

namespace Botble\Ecommerce\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

trait HasUniqueCode
{
    abstract public static function generateUniqueCode(): string;

    /**
     * Codes are derived from the latest inserted id, so two concurrent requests (a double-submitted
     * checkout for example) can generate the same code and both pass the uniqueness check before
     * either row is stored. Retrying the insert with a freshly generated code keeps the request
     * working instead of failing with a duplicate entry error.
     */
    protected function performInsert(Builder $query)
    {
        $maxAttempts = 5;

        for ($attempt = 1; ; $attempt++) {
            try {
                return parent::performInsert($query);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= $maxAttempts || ! $this->isDuplicatedCodeException($exception)) {
                    throw $exception;
                }

                $this->code = static::generateUniqueCode();
            }
        }
    }

    protected function isDuplicatedCodeException(UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, sprintf('%s_code_unique', $this->getTable()))
            || str_contains($message, sprintf('%s.code', $this->getTable()));
    }
}
