<?php

namespace App\Services\Forms;

use App\Models\SurveyForm;
use App\Models\SurveyFormVersion;
use App\Models\SurveyResponse;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FormResultsService
{
    public function __construct(private readonly FormDefinitionService $definitions) {}

    public function query(SurveyForm $form, SurveyFormVersion $version): Builder
    {
        return SurveyResponse::query()->where('survey_form_id', $form->id)->where('survey_form_version_id', $version->id);
    }

    public function summary(SurveyForm $form, SurveyFormVersion $version): array
    {
        $summary = [];
        foreach ($version->definition['questions'] as $question) {
            $options = $question['type'] === 'rating' ? array_map(fn ($value) => ['id' => (string) $value, 'label' => ['en' => (string) $value]], range(1, 5)) : $question['options'];
            $summary[$question['id']] = ['question' => $question, 'answered' => 0, 'sum' => 0, 'average' => null, 'counts' => array_map(fn ($option) => [...$option, 'count' => 0], $options)];
        }
        foreach ($this->query($form, $version)->select(['id', 'answers'])->lazyById(200) as $response) {
            foreach ($summary as $id => &$row) {
                $value = $response->answers[$id] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                $row['answered']++;
                if (in_array($row['question']['type'], ['number', 'rating'], true)) {
                    $row['sum'] += $value;
                }
                foreach ($row['counts'] as &$option) {
                    if (in_array((string) $option['id'], array_map('strval', is_array($value) ? $value : [$value]), true)) {
                        $option['count']++;
                    }
                }
                unset($option);
            }
            unset($row);
        }
        foreach ($summary as &$row) {
            if ($row['answered'] && in_array($row['question']['type'], ['number', 'rating'], true)) {
                $row['average'] = round($row['sum'] / $row['answered'], 2);
            }
            unset($row['sum']);
        }

        return array_values($summary);
    }

    public function response(SurveyResponse $response): array
    {
        return [
            'id' => $response->id, 'respondent' => $response->user?->name ?? __('forms.guest'),
            'created_at' => $response->created_at->toIso8601String(), 'updated_at' => $response->updated_at->toIso8601String(),
            'answers' => array_map(fn ($question) => ['question' => $this->definitions->text($question['label']), 'answer' => $this->answer($question, $response->answers[$question['id']] ?? null)], $response->version->definition['questions']),
        ];
    }

    private function answer(array $question, mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (in_array($question['type'], FormDefinitionService::CHOICES, true)) {
            $options = collect($question['options'])->keyBy('id');

            return implode('; ', array_map(fn ($id) => $this->definitions->text($options[$id]['label'] ?? []), is_array($value) ? $value : [$value]));
        }

        return (string) $value;
    }

    public function csv(SurveyForm $form, SurveyFormVersion $version): StreamedResponse
    {
        return response()->streamDownload(function () use ($form, $version) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            $write = function (array $cells) use ($stream) {
                // Prevent spreadsheet formulas, including prefixes hidden behind whitespace.
                $cells = array_map(fn ($cell) => preg_match('/^[\s\x00-\x1F]*[=+@\-]/u', (string) $cell) ? "'".$cell : (string) $cell, $cells);
                fputcsv($stream, $cells, ',', '"', '');
            };
            $write([__('forms.response_id'), __('forms.respondent'), __('forms.submitted_at'), __('forms.updated_at'), ...array_map(fn ($question) => $this->definitions->text($question['label']), $version->definition['questions'])]);
            foreach ($this->query($form, $version)->with('user:id,name')->lazyById(200) as $response) {
                $write([$response->id, $response->user?->name ?? __('forms.guest'), $response->created_at->toIso8601String(), $response->updated_at->toIso8601String(), ...array_map(fn ($question) => $this->answer($question, $response->answers[$question['id']] ?? null), $version->definition['questions'])]);
            }
            fclose($stream);
        }, $form->slug.'-v'.$version->number.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
