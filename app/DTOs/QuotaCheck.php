<?php

namespace App\DTOs;

use Illuminate\Database\Eloquent\Model;

final readonly class QuotaCheck
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $key,
        public Model $subject,
        public array $context = [],
        public int $amount = 1,
    ) {}
}
