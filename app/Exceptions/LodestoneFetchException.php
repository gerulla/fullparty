<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Thrown when fetching data from Lodestone fails.
 */
class LodestoneFetchException extends Exception
{
    public static function httpError(string $url, int $statusCode, ?Throwable $previous = null): self
    {
        return new self(
            "Failed to fetch Lodestone page: {$url} (HTTP {$statusCode})",
            $statusCode,
            $previous
        );
    }

    public static function networkError(string $url, ?Throwable $previous = null): self
    {
        return new self(
            "Network error while fetching Lodestone page: {$url}",
            0,
            $previous
        );
    }

    public static function timeout(string $url): self
    {
        return new self("Request timeout while fetching Lodestone page: {$url}");
    }

    public static function characterNotFound(string $lodestoneId): self
    {
        return new self("Character not found on Lodestone: {$lodestoneId}", 404);
    }
}
