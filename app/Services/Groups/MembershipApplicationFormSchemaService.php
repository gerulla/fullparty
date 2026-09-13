<?php

namespace App\Services\Groups;

use App\Models\Group;
use App\Support\Input\TextInputSanitizer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MembershipApplicationFormSchemaService
{
    public const TYPE_SMALL_TEXT = 'small_text';

    public const TYPE_BIG_TEXT = 'big_text';

    public const TYPE_SELECT = 'select';

    public const TYPE_TOGGLE = 'toggle';

    public const MAX_FIELDS = 50;

    private const MAX_OPTIONS = 25;

    private const MAX_NAME_LENGTH = 120;

    private const MAX_DESCRIPTION_LENGTH = 500;

    private const MAX_SMALL_TEXT_ANSWER_LENGTH = 1000;

    private const MAX_BIG_TEXT_ANSWER_LENGTH = 1000;

    private const LOCALES = ['en', 'de', 'fr', 'ja'];

    private const TYPES = [
        self::TYPE_SMALL_TEXT,
        self::TYPE_BIG_TEXT,
        self::TYPE_SELECT,
        self::TYPE_TOGGLE,
    ];

    public function __construct(
        private readonly TextInputSanitizer $textInputSanitizer,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaultSchema(): array
    {
        return [[
            'id' => 'are_you_a_gamer',
            'type' => self::TYPE_TOGGLE,
            'name' => [
                'en' => __('ui.gamer_question', [], 'en'),
                'de' => __('ui.gamer_question', [], 'de'),
                'fr' => __('ui.gamer_question', [], 'fr'),
                'ja' => __('ui.gamer_question', [], 'ja'),
            ],
            'description' => [],
            'required' => true,
            'options' => [],
        ]];
    }

    public function ensureDefaultForm(Group $group): void
    {
        if ($group->join_mode !== Group::JOIN_MODE_APPLICATION) {
            return;
        }

        if (filled($group->membership_application_schema)) {
            return;
        }

        $group->forceFill([
            'membership_application_schema' => $this->defaultSchema(),
        ])->save();
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function normalizeAndValidateSchema(mixed $fields): array
    {
        $errors = [];

        if (! is_array($fields)) {
            throw ValidationException::withMessages([
                'fields' => __('groups.membership_applications.form.validation.fields_required'),
            ]);
        }

        if ($fields === []) {
            throw ValidationException::withMessages([
                'fields' => __('groups.membership_applications.form.validation.minimum_fields'),
            ]);
        }

        if (count($fields) > self::MAX_FIELDS) {
            throw ValidationException::withMessages([
                'fields' => __('groups.membership_applications.form.validation.max_fields', ['max' => self::MAX_FIELDS]),
            ]);
        }

        $normalized = [];
        $seenIds = [];

        foreach (array_values($fields) as $index => $field) {
            $path = "fields.$index";

            if (! is_array($field)) {
                $errors[$path] = __('groups.membership_applications.form.validation.field_invalid');

                continue;
            }

            $type = is_string($field['type'] ?? null) ? $field['type'] : '';

            if (! in_array($type, self::TYPES, true)) {
                $errors["$path.type"] = __('groups.membership_applications.form.validation.type_invalid');

                continue;
            }

            $id = $this->normalizeIdentifier($field['id'] ?? null);

            if ($id === null) {
                $id = $this->generateFieldId($field['name']['en'] ?? null, $index);
            }

            if (isset($seenIds[$id])) {
                $id = $id.'-'.$index;
            }

            $seenIds[$id] = true;

            $name = $this->normalizeLocalizedText(
                value: $field['name'] ?? null,
                attribute: "$path.name",
                maxLength: self::MAX_NAME_LENGTH,
                requirePrimaryLocale: true,
                multiline: false,
                errors: $errors,
            );

            $description = $this->normalizeLocalizedText(
                value: $field['description'] ?? [],
                attribute: "$path.description",
                maxLength: self::MAX_DESCRIPTION_LENGTH,
                requirePrimaryLocale: false,
                multiline: true,
                errors: $errors,
            );

            $options = $type === self::TYPE_SELECT
                ? $this->normalizeSelectOptions($field['options'] ?? [], $path, $errors)
                : [];

            $normalized[] = [
                'id' => $id,
                'type' => $type,
                'name' => $name,
                'description' => $description,
                'required' => (bool) ($field['required'] ?? false),
                'options' => $options,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function normalizeAndValidateAnswers(mixed $answers, array $schema): array
    {
        $answerPayload = is_array($answers) ? $answers : [];
        $errors = [];
        $normalized = [];
        $fieldIds = collect($schema)
            ->pluck('id')
            ->filter(fn (mixed $id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        foreach ($schema as $field) {
            if (! is_array($field) || ! is_string($field['id'] ?? null)) {
                continue;
            }

            $fieldId = $field['id'];
            $type = (string) ($field['type'] ?? self::TYPE_SMALL_TEXT);
            $required = (bool) ($field['required'] ?? false);
            $hasAnswer = array_key_exists($fieldId, $answerPayload);
            $value = $hasAnswer ? $answerPayload[$fieldId] : null;

            $normalized[$fieldId] = match ($type) {
                self::TYPE_BIG_TEXT => $this->normalizeTextAnswer($value, $fieldId, $required, self::MAX_BIG_TEXT_ANSWER_LENGTH, true, $errors),
                self::TYPE_SELECT => $this->normalizeSelectAnswer($value, $field, $fieldId, $required, $errors),
                self::TYPE_TOGGLE => $this->normalizeToggleAnswer($value, $fieldId, $required, $hasAnswer, $errors),
                default => $this->normalizeTextAnswer($value, $fieldId, $required, self::MAX_SMALL_TEXT_ANSWER_LENGTH, false, $errors),
            };
        }

        foreach (array_keys($answerPayload) as $answerKey) {
            if (! in_array($answerKey, $fieldIds, true)) {
                $errors["answers.$answerKey"] = __('groups.membership_applications.form.validation.answer_unknown');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function normalizeIdentifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = Str::slug($value);

        if ($normalized === '') {
            return null;
        }

        return Str::limit($normalized, 64, '');
    }

    private function generateFieldId(mixed $name, int $index): string
    {
        $base = is_string($name) ? Str::slug($name) : '';

        if ($base === '') {
            $base = 'question';
        }

        return Str::limit($base, 56, '').'-'.($index + 1);
    }

    /**
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    private function normalizeLocalizedText(
        mixed $value,
        string $attribute,
        int $maxLength,
        bool $requirePrimaryLocale,
        bool $multiline,
        array &$errors,
    ): array {
        if (! is_array($value)) {
            if ($requirePrimaryLocale) {
                $errors["$attribute.en"] = __('groups.membership_applications.form.validation.name_required');
            }

            return [];
        }

        $normalized = [];

        foreach (self::LOCALES as $locale) {
            $entry = $value[$locale] ?? null;

            if ($entry === null || $entry === '') {
                continue;
            }

            if (! is_string($entry)) {
                $errors["$attribute.$locale"] = __('groups.membership_applications.form.validation.localized_text_invalid');

                continue;
            }

            $sanitized = $multiline
                ? $this->textInputSanitizer->sanitizeMultiline($entry)
                : $this->textInputSanitizer->sanitizeSingleLine($entry);

            if ($sanitized === null || $sanitized === '') {
                continue;
            }

            if (mb_strlen($sanitized) > $maxLength) {
                $errors["$attribute.$locale"] = __('groups.membership_applications.form.validation.localized_text_max', ['max' => $maxLength]);

                continue;
            }

            $normalized[$locale] = $sanitized;
        }

        if ($requirePrimaryLocale && ! array_key_exists('en', $normalized)) {
            $errors["$attribute.en"] = __('groups.membership_applications.form.validation.name_required');
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $errors
     * @return array<int, array{id: string, label: array<string, string>}>
     */
    private function normalizeSelectOptions(mixed $options, string $path, array &$errors): array
    {
        if (! is_array($options) || $options === []) {
            $errors["$path.options"] = __('groups.membership_applications.form.validation.options_required');

            return [];
        }

        if (count($options) > self::MAX_OPTIONS) {
            $errors["$path.options"] = __('groups.membership_applications.form.validation.options_max', ['max' => self::MAX_OPTIONS]);
        }

        $normalized = [];
        $seenIds = [];

        foreach (array_values($options) as $index => $option) {
            $optionPath = "$path.options.$index";

            if (! is_array($option)) {
                $errors[$optionPath] = __('groups.membership_applications.form.validation.option_invalid');

                continue;
            }

            $label = $this->normalizeLocalizedText(
                value: $option['label'] ?? null,
                attribute: "$optionPath.label",
                maxLength: self::MAX_NAME_LENGTH,
                requirePrimaryLocale: true,
                multiline: false,
                errors: $errors,
            );

            $id = $this->normalizeIdentifier($option['id'] ?? null)
                ?? $this->generateFieldId($label['en'] ?? null, $index);

            if (isset($seenIds[$id])) {
                $id = $id.'-'.$index;
            }

            $seenIds[$id] = true;
            $normalized[] = [
                'id' => $id,
                'label' => $label,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function normalizeTextAnswer(
        mixed $value,
        string $fieldId,
        bool $required,
        int $maxLength,
        bool $multiline,
        array &$errors,
    ): ?string {
        if ($value === null || $value === '') {
            if ($required) {
                $errors["answers.$fieldId"] = __('groups.membership_applications.form.validation.answer_required');
            }

            return null;
        }

        if (! is_string($value)) {
            $errors["answers.$fieldId"] = __('groups.membership_applications.form.validation.answer_invalid');

            return null;
        }

        $sanitized = $multiline
            ? $this->textInputSanitizer->sanitizeMultiline($value)
            : $this->textInputSanitizer->sanitizeSingleLine($value);

        if ($sanitized === null || $sanitized === '') {
            if ($required) {
                $errors["answers.$fieldId"] = __('groups.membership_applications.form.validation.answer_required');
            }

            return null;
        }

        if (mb_strlen($sanitized) > $maxLength) {
            $errors["answers.$fieldId"] = __('groups.membership_applications.form.validation.answer_max', ['max' => $maxLength]);
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, string>  $errors
     */
    private function normalizeSelectAnswer(
        mixed $value,
        array $field,
        string $fieldId,
        bool $required,
        array &$errors,
    ): ?string {
        if ($value === null || $value === '') {
            if ($required) {
                $errors["answers.$fieldId"] = __('groups.membership_applications.form.validation.answer_required');
            }

            return null;
        }

        if (! is_string($value)) {
            $errors["answers.$fieldId"] = __('groups.membership_applications.form.validation.answer_invalid');

            return null;
        }

        $optionIds = collect($field['options'] ?? [])
            ->pluck('id')
            ->filter(fn (mixed $id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        if (! in_array($value, $optionIds, true)) {
            $errors["answers.$fieldId"] = __('groups.membership_applications.form.validation.answer_option_invalid');

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function normalizeToggleAnswer(
        mixed $value,
        string $fieldId,
        bool $required,
        bool $hasAnswer,
        array &$errors,
    ): ?bool {
        if (! $hasAnswer || $value === null || $value === '') {
            if ($required) {
                $errors["answers.$fieldId"] = __('groups.membership_applications.form.validation.answer_required');
            }

            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, '1', 'true'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false'], true)) {
            return false;
        }

        $errors["answers.$fieldId"] = __('groups.membership_applications.form.validation.answer_invalid');

        return null;
    }
}
