<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Thrown when parsing Lodestone HTML fails.
 */
class LodestoneParseException extends Exception
{
    public static function missingElement(string $element, string $context = ''): self
    {
        $message = "Failed to parse Lodestone page: missing element '{$element}'";
        if ($context) {
            $message .= " in {$context}";
        }
        return new self($message);
    }

    public static function invalidStructure(string $message, ?Throwable $previous = null): self
    {
        return new self("Invalid Lodestone page structure: {$message}", 0, $previous);
    }

    public static function emptyResponse(string $url): self
    {
        return new self("Empty or invalid response from Lodestone: {$url}");
    }

    public static function malformedHtml(string $context, ?Throwable $previous = null): self
    {
        return new self("Malformed HTML in {$context}", 0, $previous);
    }
}
