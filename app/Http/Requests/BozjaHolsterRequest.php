<?php

namespace App\Http\Requests;

use App\Models\BozjaHolster;
use App\Models\BozjaItem;
use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BozjaHolsterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = $this->route('group');

        if (! $group instanceof Group) {
            return false;
        }

        $group->loadMissing('memberships');

        return $group->hasModeratorAccess($this->user()?->id);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'array'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'name.de' => ['nullable', 'string', 'max:255'],
            'name.fr' => ['nullable', 'string', 'max:255'],
            'name.ja' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::in(BozjaHolster::ROLES)],
            'max_capacity' => ['prohibited'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'guide' => ['nullable', 'string', 'max:50000'],
            'items' => ['present', 'array', 'max:250'],
            'items.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('bozja_items', 'id')->where('is_active', true),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items', []);
            $holster = $this->route('bozjaHolster');
            $maxCapacity = $holster instanceof BozjaHolster
                ? $holster->max_capacity
                : BozjaHolster::DEFAULT_MAX_CAPACITY;

            if (! is_array($items) || $validator->errors()->has('items')) {
                return;
            }

            $weights = BozjaItem::query()
                ->whereIn('id', collect($items)->pluck('id')->filter())
                ->pluck('cache_weight', 'id');
            $capacity = collect($items)->sum(fn ($item) => is_array($item)
                ? (int) ($weights[(int) ($item['id'] ?? 0)] ?? 0) * (int) ($item['quantity'] ?? 0)
                : 0);

            if ($capacity > $maxCapacity) {
                $validator->errors()->add('items', 'The selected items exceed this holster\'s maximum capacity.');
            }
        });
    }
}
