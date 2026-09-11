<?php

namespace App\Http\Requests;

use App\Models\BozjaHolster;
use App\Models\BozjaItem;
use App\Models\Group;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class BozjaHolsterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = $this->route('group');

        if (! $group instanceof Group) {
            return false;
        }
        $holster = $this->route('bozjaHolster');
        abort_if($holster instanceof BozjaHolster && $holster->group_id !== $group->id, 404);

        $group->loadMissing('memberships');

        return $group->hasModeratorAccess($this->user()?->id);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $group = $this->route('group');
        $groupId = $group instanceof Group ? $group->id : null;

        return [
            'name' => ['nullable', 'array'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'name.de' => ['nullable', 'string', 'max:255'],
            'name.fr' => ['nullable', 'string', 'max:255'],
            'name.ja' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::in(BozjaHolster::ROLES)],
            'type' => ['required', 'string', Rule::in(BozjaHolster::TYPES)],
            'parent_holster_id' => [
                Rule::requiredIf(fn () => $this->input('type') === BozjaHolster::TYPE_REFILL),
                Rule::prohibitedIf(fn () => $this->input('type') !== BozjaHolster::TYPE_REFILL),
                'nullable',
                'integer',
                Rule::exists('bozja_holsters', 'id')->where(fn ($query) => $query
                    ->where('group_id', $groupId)
                    ->where('type', BozjaHolster::TYPE_PREPOP)),
            ],
            'max_capacity' => ['prohibited'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'guide' => ['nullable', 'array'],
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
            $holster = $this->route('bozjaHolster');
            if ($this->exists('guide') && $holster instanceof BozjaHolster && $holster->guide_format === 'markdown' && filled($holster->getRawOriginal('guide'))) {
                $validator->errors()->add('guide', __('rich_text.conversion_required'));

                return;
            }
            if (! is_array($this->input('guide')) || $validator->errors()->has('guide')) {
                return;
            }
            $documents = app(RichTextDocument::class);
            $document = $documents->validate($this->input('guide'), 'guide', 50000);
            // Group resource assets have a separate permission and revision lifecycle.
            foreach ($documents->imageUrls($document) as $url) {
                if (str_starts_with(rawurldecode(parse_url($url, PHP_URL_PATH) ?? ''), '/resource-assets/')) {
                    throw ValidationException::withMessages(['guide' => __('rich_text.invalid')]);
                }
            }
            $this->merge(['guide' => $document]);
            $validator->setData(array_replace($validator->getData(), ['guide' => $document]));
        });
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items', []);
            $holster = $this->route('bozjaHolster');

            if ($holster instanceof BozjaHolster) {
                if ((int) $this->input('parent_holster_id') === (int) $holster->id) {
                    $validator->errors()->add('parent_holster_id', 'A holster cannot refill itself.');
                }

                if ($this->input('type') !== BozjaHolster::TYPE_PREPOP
                    && $holster->refillHolsters()->exists()) {
                    $validator->errors()->add('type', 'A prepop holster with refills cannot be changed to a refill.');
                }
            }

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
