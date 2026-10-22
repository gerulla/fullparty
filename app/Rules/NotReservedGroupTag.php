<?php

namespace App\Rules;

use App\Services\Groups\GroupExtraTagPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class NotReservedGroupTag implements ValidationRule
{
    /** @var array<string, bool> */
    private readonly array $reservedKeys;

    public function __construct(private readonly GroupExtraTagPolicy $policy)
    {
        $this->reservedKeys = array_fill_keys($policy->reservedKeys(), true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && isset($this->reservedKeys[$this->policy->normalizedKey($value)])) {
            $fail(__('groups.common.validation.tag_covered_by_field'));
        }
    }
}
