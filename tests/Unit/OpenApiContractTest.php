<?php

use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\OpenApiContract;

it('checks referenced nullable alternatives rather than accepting any value', function () {
    $document = ['components' => ['schemas' => ['Run' => [
        'type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']],
    ]]]];
    $schema = ['anyOf' => [['$ref' => '#/components/schemas/Run'], ['type' => 'null']]];

    OpenApiContract::assertMatches(null, $schema, $document);
    OpenApiContract::assertMatches((object) ['id' => 12], $schema, $document);

    expect(fn () => OpenApiContract::assertMatches((object) ['id' => 'invalid'], $schema, $document))
        ->toThrow(AssertionFailedError::class);
    expect(fn () => OpenApiContract::assertMatches((object) [], $schema, $document))
        ->toThrow(AssertionFailedError::class);
});

it('requires exactly one matching oneOf branch', function () {
    $schema = ['oneOf' => [['type' => 'string'], ['type' => 'integer']]];
    OpenApiContract::assertMatches('text', $schema, []);

    expect(fn () => OpenApiContract::assertMatches(null, $schema, []))->toThrow(AssertionFailedError::class);
    expect(fn () => OpenApiContract::assertMatches(12, ['oneOf' => [['type' => 'integer'], ['type' => 'number']]], []))
        ->toThrow(AssertionFailedError::class);
});

it('requires every allOf branch to match', function () {
    $schema = ['allOf' => [['type' => 'string'], ['enum' => ['ready']]]];
    OpenApiContract::assertMatches('ready', $schema, []);

    expect(fn () => OpenApiContract::assertMatches('invalid', $schema, []))->toThrow(AssertionFailedError::class);
    expect(fn () => OpenApiContract::assertMatches(null, $schema, []))->toThrow(AssertionFailedError::class);
});
