<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when the provided Lodestone ID or URL is invalid.
 */
class LodestoneInvalidInputException extends Exception
{
    public static function invalidFormat(string $input): self
    {
        return new self("Invalid Lodestone ID or URL format: {$input}");
    }

    public static function notNumeric(string $input): self
    {
        return new self("Lodestone ID must be numeric: {$input}");
    }

    public static function empty(): self
    {
        return new self('Lodestone ID or URL cannot be empty');
    }
}
