<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use stdClass;

/** Checks the structural JSON Schema keywords used by the API response contract tests. */
final class OpenApiContract
{
    public static function assertMatches(mixed $value, array $schema, array $document, string $path = '$'): void
    {
        if (isset($schema['$ref'])) {
            $resolved = $document;
            foreach (explode('/', substr($schema['$ref'], 2)) as $key) {
                $resolved = $resolved[str_replace(['~1', '~0'], ['/', '~'], $key)];
            }
            self::assertMatches($value, $resolved, $document, $path);

            return;
        }

        foreach ($schema['allOf'] ?? [] as $branch) {
            self::assertMatches($value, $branch, $document, $path);
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (! isset($schema[$keyword])) {
                continue;
            }

            $matches = 0;
            $failures = [];
            foreach ($schema[$keyword] as $branch) {
                try {
                    self::assertMatches($value, $branch, $document, $path);
                    $matches++;
                } catch (AssertionFailedError $failure) {
                    $failures[] = $failure->getMessage();
                }
            }

            $message = $path.' does not match '.$keyword.': '.implode('; ', $failures);
            if ($keyword === 'oneOf') {
                Assert::assertSame(1, $matches, $message);
            } else {
                Assert::assertGreaterThan(0, $matches, $message);
            }
        }

        if (isset($schema['type'])) {
            $matches = array_filter((array) $schema['type'], fn ($type) => match ($type) {
                'object' => $value instanceof stdClass,
                'array' => is_array($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            });
            Assert::assertNotEmpty($matches, $path.' does not match documented type '.json_encode($schema['type']));
        }

        if (isset($schema['enum'])) {
            Assert::assertContains($value, $schema['enum'], $path.' is outside the documented enum');
        }
        if (array_key_exists('const', $schema)) {
            Assert::assertSame($schema['const'], $value, $path.' does not match the documented constant');
        }

        if ($value instanceof stdClass) {
            foreach ($schema['required'] ?? [] as $key) {
                Assert::assertTrue(property_exists($value, $key), $path.'.'.$key.' is required by the contract');
            }
            foreach (get_object_vars($value) as $key => $child) {
                $childSchema = $schema['properties'][$key] ?? $schema['additionalProperties'] ?? true;
                Assert::assertNotFalse($childSchema, $path.'.'.$key.' is not documented');
                if (is_array($childSchema)) {
                    self::assertMatches($child, $childSchema, $document, $path.'.'.$key);
                }
            }
        }
        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $index => $child) {
                self::assertMatches($child, $schema['items'], $document, $path.'['.$index.']');
            }
        }
    }
}
