<?php

namespace App\Services\Groups;

use App\Http\Controllers\Concerns\InteractsWithActivitySlotFieldDisplay;
use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotFieldValue;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ZipArchive;

class ActivityRosterSpreadsheetExportService
{
    use InteractsWithActivitySlotFieldDisplay;

    private const SHEET_NAME = 'Roster';

    public function __construct(
        private readonly ActivitySlotBench $slotBench,
        private readonly ActivityRosterSummaryPresetBuilder $summaryPresetBuilder,
    ) {}

    public function filename(Activity $activity): string
    {
        $baseName = filled($activity->title)
            ? (string) $activity->title
            : sprintf('activity-%d-roster', $activity->id);

        return sprintf('%s.xlsx', Str::slug($baseName) ?: sprintf('activity-%d-roster', $activity->id));
    }

    public function render(Activity $activity): string
    {
        $payload = $this->build($activity);

        return $this->buildWorkbookBinary($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Activity $activity): array
    {
        $activity->loadMissing([
            'group',
            'activityTypeVersion',
            'slots.assignedCharacter',
            'slots.assignments',
            'slots.fieldValues',
            'slots.compositionHints.characterClass',
        ]);

        $sortedSlots = $activity->slots->sortBy('sort_order')->values();
        $mainGroups = $sortedSlots
            ->reject(fn (ActivitySlot $slot) => $this->slotBench->isBench($slot))
            ->groupBy(fn (ActivitySlot $slot) => $slot->group_key)
            ->map(fn (Collection $slots, string $groupKey) => $this->buildGroup($groupKey, $slots->values()))
            ->values();

        $hasPhantomJobs = $sortedSlots
            ->flatMap(fn (ActivitySlot $slot) => $slot->fieldValues)
            ->contains(fn (ActivitySlotFieldValue $fieldValue) => $fieldValue->source === 'phantom_jobs');

        $rowPositions = $mainGroups
            ->flatMap(fn (array $group) => array_keys($group['slots_by_position']))
            ->unique()
            ->sort()
            ->values();

        $rows = $rowPositions
            ->map(function (int $position) use ($mainGroups, $hasPhantomJobs) {
                $referenceSlot = collect($mainGroups)
                    ->map(fn (array $group) => $group['slots_by_position'][$position] ?? null)
                    ->first(fn (?ActivitySlot $slot) => $slot instanceof ActivitySlot);

                $theme = $this->themeForSlot($referenceSlot);

                return [
                    'label' => $referenceSlot
                        ? ($this->localizedText($referenceSlot->slot_label) ?: sprintf('Slot %d', $position))
                        : sprintf('Slot %d', $position),
                    'theme' => $theme,
                    'cells' => collect($mainGroups)
                        ->map(fn (array $group) => $this->buildSlotCell(
                            $group['slots_by_position'][$position] ?? null,
                            $theme,
                            $hasPhantomJobs
                        ))
                        ->all(),
                ];
            })
            ->all();

        $benchSlots = $sortedSlots
            ->filter(fn (ActivitySlot $slot) => $this->slotBench->isBench($slot))
            ->values()
            ->map(fn (ActivitySlot $slot) => $this->buildSlotCell($slot, 'bench', $hasPhantomJobs))
            ->all();

        return [
            'title' => filled($activity->title) ? (string) $activity->title : sprintf('Run #%d', $activity->id),
            'group_name' => $activity->group?->name ?? '',
            'scheduled_for' => $activity->starts_at,
            'duration_hours' => $activity->duration_hours,
            'groups' => $mainGroups->all(),
            'rows' => $rows,
            'bench_slots' => $benchSlots,
            'has_phantom_jobs' => $hasPhantomJobs,
            'requirements' => $this->buildRequirementsSummary(
                $this->summaryPresetBuilder->buildForActivity($activity)[0]['requirements'] ?? []
            ),
            'class_options' => CharacterClass::query()
                ->orderBy('role')
                ->orderBy('shorthand')
                ->get(['name', 'shorthand', 'role'])
                ->map(fn (CharacterClass $characterClass) => [
                    'value' => $characterClass->shorthand,
                    'label' => $characterClass->name,
                    'role' => $characterClass->role,
                ])
                ->all(),
            'phantom_job_options' => $hasPhantomJobs
                ? PhantomJob::query()
                    ->orderBy('name')
                    ->get(['name'])
                    ->map(fn (PhantomJob $phantomJob) => [
                        'value' => $phantomJob->name,
                        'label' => $phantomJob->name,
                    ])
                    ->all()
                : [],
        ];
    }

    /**
     * @param  Collection<int, ActivitySlot>  $slots
     * @return array{key: string, label: string, slots_by_position: array<int, ActivitySlot>}
     */
    private function buildGroup(string $groupKey, Collection $slots): array
    {
        $orderedSlots = $slots->sortBy('sort_order')->values();
        $firstSlot = $orderedSlots->first();

        return [
            'key' => $groupKey,
            'label' => $firstSlot
                ? ($this->localizedText($firstSlot->group_label) ?: $groupKey)
                : $groupKey,
            'slots_by_position' => $orderedSlots
                ->keyBy(fn (ActivitySlot $slot) => (int) $slot->position_in_group)
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSlotCell(?ActivitySlot $slot, string $fallbackTheme, bool $hasPhantomJobs): array
    {
        if (! $slot) {
            return [
                'theme' => $fallbackTheme,
                'name_text' => '',
                'class_text' => '',
                'phantom_text' => '',
            ];
        }

        $theme = $this->themeForSlot($slot) ?: $fallbackTheme;
        $classField = $slot->fieldValues->firstWhere('source', 'character_classes');
        $phantomField = $hasPhantomJobs
            ? $slot->fieldValues->firstWhere('source', 'phantom_jobs')
            : null;

        $classMeta = $classField ? ($this->resolveSlotFieldDisplayMeta($classField) ?? []) : [];

        $nameLines = [];

        if ($slot->assignedCharacter) {
            $nameLines[] = $slot->assignedCharacter->name;

            $worldDatacenter = collect([
                $slot->assignedCharacter->world,
                $slot->assignedCharacter->datacenter,
            ])->filter(fn (?string $value) => filled($value))->implode(' - ');

            if ($worldDatacenter !== '') {
                $nameLines[] = $worldDatacenter;
            }

            $designationText = collect([
                $slot->is_host ? 'Host' : null,
                $slot->is_raid_leader ? 'Raid Leader' : null,
            ])->filter()->implode(' • ');

            if ($designationText !== '') {
                $nameLines[] = $designationText;
            }
        }

        return [
            'theme' => $theme,
            'name_text' => implode("\n", $nameLines),
            'class_text' => $slot->assignedCharacter
                ? trim((string) ($classMeta['shorthand'] ?? $this->displayValueToString($classField)))
                : '',
            'phantom_text' => $slot->assignedCharacter && $phantomField
                ? $this->displayValueToString($phantomField)
                : '',
        ];
    }

    private function buildWorkbookBinary(array $payload): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'fullparty-roster-');

        if ($tempFile === false) {
            throw new \RuntimeException('Unable to create a temporary spreadsheet file.');
        }

        $zip = new ZipArchive;

        if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
            @unlink($tempFile);

            throw new \RuntimeException('Unable to open the temporary spreadsheet archive.');
        }

        $sheet = $this->buildSheetLayout($payload);
        $styles = $this->buildStylesXml();

        $zip->addFromString('[Content_Types].xml', $this->buildContentTypesXml());
        $zip->addFromString('_rels/.rels', $this->buildRootRelationshipsXml());
        $zip->addFromString('docProps/app.xml', $this->buildAppPropertiesXml());
        $zip->addFromString('docProps/core.xml', $this->buildCorePropertiesXml());
        $zip->addFromString('xl/workbook.xml', $this->buildWorkbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRelationshipsXml());
        $zip->addFromString('xl/styles.xml', $styles['xml']);
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->buildWorksheetXml(
            $sheet,
            $styles['style_map'],
            $styles['dxf_map'] ?? [],
        ));

        $zip->close();

        $binary = file_get_contents($tempFile);
        @unlink($tempFile);

        if ($binary === false) {
            throw new \RuntimeException('Unable to read the generated spreadsheet.');
        }

        return $binary;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSheetLayout(array $payload): array
    {
        $groupSpan = $payload['has_phantom_jobs'] ? 3 : 2;
        $groupCount = count($payload['groups']);
        $topGroupCount = max(1, (int) ceil($groupCount / 2));
        $bottomGroupCount = max(0, $groupCount - $topGroupCount);
        $topGroups = array_slice($payload['groups'], 0, $topGroupCount);
        $bottomGroups = array_slice($payload['groups'], $topGroupCount);
        $topRows = array_map(fn (array $row) => [
            'label' => $row['label'],
            'theme' => $row['theme'],
            'cells' => array_slice($row['cells'], 0, $topGroupCount),
        ], $payload['rows']);
        $bottomRows = array_map(fn (array $row) => [
            'label' => $row['label'],
            'theme' => $row['theme'],
            'cells' => array_slice($row['cells'], $topGroupCount),
        ], $payload['rows']);

        $leftSectionWidth = max($topGroupCount, $bottomGroupCount) * $groupSpan;
        $benchWidth = $payload['has_phantom_jobs'] ? 4 : 3;
        $requirementItemCounts = array_map(
            fn (array $row) => count($row['items']),
            $payload['requirements']
        );
        $maxRequirementItems = $requirementItemCounts === []
            ? 0
            : max($requirementItemCounts);
        $requirementsWidth = max(
            1,
            1 + $maxRequirementItems
        );
        $rightSectionWidth = max($benchWidth, $requirementsWidth);
        $rightStartColumn = $leftSectionWidth + 2;
        $visibleLastColumnIndex = max($leftSectionWidth, $rightStartColumn + $rightSectionWidth - 1);
        $lastColumn = $this->columnLetter($visibleLastColumnIndex);
        $rows = [];
        $merges = [];
        $dataValidations = [];
        $conditionalFormats = [];
        $columnWidths = [];
        $groupPhantomRanges = [];

        $titleParts = array_filter([
            $payload['group_name'] !== '' ? $payload['group_name'] : null,
            $payload['title'],
            $payload['scheduled_for'] ? $payload['scheduled_for']->format('d/m/Y H:i').' UTC' : null,
            $payload['duration_hours'] ? rtrim(rtrim(number_format((float) $payload['duration_hours'], 1, '.', ''), '0'), '.').'h' : null,
        ]);

        $rows[1] = [
            'height' => 24,
            'cells' => [
                1 => ['value' => implode(' | ', $titleParts), 'style' => 'title'],
            ],
        ];
        $merges[] = sprintf('A1:%s1', $lastColumn);

        $rows[2] = [
            'height' => 22,
            'cells' => [],
        ];

        foreach ($topGroups as $groupIndex => $group) {
            $startColumn = 1 + ($groupIndex * $groupSpan);
            $endColumn = $startColumn + $groupSpan - 1;
            $columnWidths[$startColumn] = 24;
            $columnWidths[$startColumn + 1] = 13;

            if ($payload['has_phantom_jobs']) {
                $columnWidths[$startColumn + 2] = 16;
            }

            $rows[2]['cells'][$startColumn] = [
                'value' => $group['label'],
                'style' => 'group_header',
            ];

            if ($endColumn > $startColumn) {
                $merges[] = sprintf('%s2:%s2', $this->columnLetter($startColumn), $this->columnLetter($endColumn));
            }
        }

        $rows[2]['cells'][$rightStartColumn] = [
            'value' => 'Bench',
            'style' => 'group_header',
        ];

        if ($rightSectionWidth > 1) {
            $merges[] = sprintf(
                '%s2:%s2',
                $this->columnLetter($rightStartColumn),
                $this->columnLetter($rightStartColumn + $rightSectionWidth - 1),
            );
        }

        $columnWidths[$rightStartColumn] = 18.5;
        $columnWidths[$rightStartColumn + 1] = 24;
        $columnWidths[$rightStartColumn + 2] = 13;

        if ($payload['has_phantom_jobs']) {
            $columnWidths[$rightStartColumn + 3] = 16;
        }

        $topSectionRowCount = max(count($topRows), count($payload['bench_slots']));

        for ($rowOffset = 0; $rowOffset < $topSectionRowCount; $rowOffset++) {
            $rowNumber = 3 + $rowOffset;
            $rows[$rowNumber] = [
                'height' => 34,
                'cells' => [],
            ];

            $leftRow = $topRows[$rowOffset] ?? null;

            if ($leftRow) {
                foreach ($leftRow['cells'] as $groupIndex => $cell) {
                    $startColumn = 1 + ($groupIndex * $groupSpan);

                    $rows[$rowNumber]['cells'][$startColumn] = [
                        'value' => $cell['name_text'],
                        'style' => sprintf('%s_name', $cell['theme']),
                    ];
                    $rows[$rowNumber]['cells'][$startColumn + 1] = [
                        'value' => $cell['class_text'],
                        'style' => sprintf('%s_selector', $cell['theme']),
                    ];
                    $dataValidations[] = [
                        'sqref' => sprintf('%s%d', $this->columnLetter($startColumn + 1), $rowNumber),
                        'option_type' => 'class',
                    ];

                    $conditionalFormats[] = [
                        'sqref' => sprintf(
                            '%s%d:%s%d',
                            $this->columnLetter($startColumn),
                            $rowNumber,
                            $this->columnLetter($startColumn + ($payload['has_phantom_jobs'] ? 2 : 1)),
                            $rowNumber,
                        ),
                        'kind' => 'class_role',
                        'class_cell' => sprintf('%s%d', $this->columnLetter($startColumn + 1), $rowNumber),
                    ];

                    if ($payload['has_phantom_jobs']) {
                        $rows[$rowNumber]['cells'][$startColumn + 2] = [
                            'value' => $cell['phantom_text'],
                            'style' => sprintf('%s_selector', $cell['theme']),
                        ];
                        $dataValidations[] = [
                            'sqref' => sprintf('%s%d', $this->columnLetter($startColumn + 2), $rowNumber),
                            'option_type' => 'phantom',
                        ];

                        $groupKey = $topGroups[$groupIndex]['key'] ?? null;

                        if (is_string($groupKey) && $groupKey !== '') {
                            $groupPhantomRanges[$groupKey][] = sprintf(
                                '%s%d',
                                $this->columnLetter($startColumn + 2),
                                $rowNumber,
                            );
                        }
                    }
                }
            }

            $benchSlot = $payload['bench_slots'][$rowOffset] ?? null;

            if ($benchSlot) {
                $rows[$rowNumber]['cells'][$rightStartColumn] = [
                    'value' => sprintf('Bench %d', $rowOffset + 1),
                    'style' => 'bench_label',
                ];
                $rows[$rowNumber]['cells'][$rightStartColumn + 1] = [
                    'value' => $benchSlot['name_text'],
                    'style' => 'bench_name',
                ];
                $rows[$rowNumber]['cells'][$rightStartColumn + 2] = [
                    'value' => $benchSlot['class_text'],
                    'style' => 'bench_selector',
                ];
                $dataValidations[] = [
                    'sqref' => sprintf('%s%d', $this->columnLetter($rightStartColumn + 2), $rowNumber),
                    'option_type' => 'class',
                ];

                $conditionalFormats[] = [
                    'sqref' => sprintf(
                        '%s%d:%s%d',
                        $this->columnLetter($rightStartColumn + 1),
                        $rowNumber,
                        $this->columnLetter($rightStartColumn + ($payload['has_phantom_jobs'] ? 3 : 2)),
                        $rowNumber,
                    ),
                    'kind' => 'class_role',
                    'class_cell' => sprintf('%s%d', $this->columnLetter($rightStartColumn + 2), $rowNumber),
                ];

                if ($payload['has_phantom_jobs']) {
                    $rows[$rowNumber]['cells'][$rightStartColumn + 3] = [
                        'value' => $benchSlot['phantom_text'],
                        'style' => 'bench_selector',
                    ];
                    $dataValidations[] = [
                        'sqref' => sprintf('%s%d', $this->columnLetter($rightStartColumn + 3), $rowNumber),
                        'option_type' => 'phantom',
                    ];
                }
            }
        }

        $bottomHeaderRow = max(4, 2 + $topSectionRowCount + 2);
        $rows[$bottomHeaderRow] = [
            'height' => 22,
            'cells' => [],
        ];

        foreach ($bottomGroups as $groupIndex => $group) {
            $startColumn = 1 + ($groupIndex * $groupSpan);
            $columnWidths[$startColumn] = 24;
            $columnWidths[$startColumn + 1] = 13;

            if ($payload['has_phantom_jobs']) {
                $columnWidths[$startColumn + 2] = 16;
            }
            $rows[$bottomHeaderRow]['cells'][$startColumn] = [
                'value' => $group['label'],
                'style' => 'group_header',
            ];

            $endColumn = $startColumn + $groupSpan - 1;

            if ($endColumn > $startColumn) {
                $merges[] = sprintf(
                    '%s%d:%s%d',
                    $this->columnLetter($startColumn),
                    $bottomHeaderRow,
                    $this->columnLetter($endColumn),
                    $bottomHeaderRow,
                );
            }
        }

        $rows[$bottomHeaderRow]['cells'][$rightStartColumn] = [
            'value' => 'Requirements',
            'style' => 'group_header',
        ];

        if ($rightSectionWidth > 1) {
            $merges[] = sprintf(
                '%s%d:%s%d',
                $this->columnLetter($rightStartColumn),
                $bottomHeaderRow,
                $this->columnLetter($rightStartColumn + $rightSectionWidth - 1),
                $bottomHeaderRow,
            );
        }

        $requirementsValueStartColumn = $rightStartColumn + 1;
        $requirementsEndColumn = $rightStartColumn + $rightSectionWidth - 1;
        $columnWidths[$rightStartColumn] = max($columnWidths[$rightStartColumn] ?? 0, 14);

        for ($offset = 0; $offset < $rightSectionWidth - 1; $offset++) {
            $columnWidths[$requirementsValueStartColumn + $offset] = max(
                $columnWidths[$requirementsValueStartColumn + $offset] ?? 0,
                18
            );
        }

        $bottomSectionRowCount = max(count($bottomRows), count($payload['requirements']));

        for ($rowOffset = 0; $rowOffset < $bottomSectionRowCount; $rowOffset++) {
            $rowNumber = $bottomHeaderRow + 1 + $rowOffset;
            $rows[$rowNumber] = [
                'height' => 34,
                'cells' => [],
            ];

            $leftRow = $bottomRows[$rowOffset] ?? null;

            if ($leftRow) {
                foreach ($leftRow['cells'] as $groupIndex => $cell) {
                    $startColumn = 1 + ($groupIndex * $groupSpan);

                    $rows[$rowNumber]['cells'][$startColumn] = [
                        'value' => $cell['name_text'],
                        'style' => sprintf('%s_name', $cell['theme']),
                    ];
                    $rows[$rowNumber]['cells'][$startColumn + 1] = [
                        'value' => $cell['class_text'],
                        'style' => sprintf('%s_selector', $cell['theme']),
                    ];
                    $dataValidations[] = [
                        'sqref' => sprintf('%s%d', $this->columnLetter($startColumn + 1), $rowNumber),
                        'option_type' => 'class',
                    ];

                    $conditionalFormats[] = [
                        'sqref' => sprintf(
                            '%s%d:%s%d',
                            $this->columnLetter($startColumn),
                            $rowNumber,
                            $this->columnLetter($startColumn + ($payload['has_phantom_jobs'] ? 2 : 1)),
                            $rowNumber,
                        ),
                        'kind' => 'class_role',
                        'class_cell' => sprintf('%s%d', $this->columnLetter($startColumn + 1), $rowNumber),
                    ];

                    if ($payload['has_phantom_jobs']) {
                        $rows[$rowNumber]['cells'][$startColumn + 2] = [
                            'value' => $cell['phantom_text'],
                            'style' => sprintf('%s_selector', $cell['theme']),
                        ];
                        $dataValidations[] = [
                            'sqref' => sprintf('%s%d', $this->columnLetter($startColumn + 2), $rowNumber),
                            'option_type' => 'phantom',
                        ];

                        $groupKey = $bottomGroups[$groupIndex]['key'] ?? null;

                        if (is_string($groupKey) && $groupKey !== '') {
                            $groupPhantomRanges[$groupKey][] = sprintf(
                                '%s%d',
                                $this->columnLetter($startColumn + 2),
                                $rowNumber,
                            );
                        }
                    }
                }
            }
        }

        foreach ($payload['requirements'] as $rowOffset => $requirementRow) {
            $rowNumber = $bottomHeaderRow + 1 + $rowOffset;

            $rows[$rowNumber]['height'] = max($rows[$rowNumber]['height'] ?? 28, 28);
            $rows[$rowNumber]['cells'][$rightStartColumn] = [
                'value' => $requirementRow['label'],
                'style' => 'neutral_label',
            ];

            foreach ($requirementRow['items'] as $itemIndex => $itemText) {
                $cellColumn = $requirementsValueStartColumn + $itemIndex;

                $rows[$rowNumber]['cells'][$cellColumn] = [
                    'value' => $itemText['text'],
                    'style' => 'reference_value',
                ];

                $scopeRefs = collect($itemText['scope_group_keys'])
                    ->flatMap(fn (string $groupKey) => $groupPhantomRanges[$groupKey] ?? [])
                    ->values()
                    ->all();

                if ($itemText['scope_type'] === 'all_slots') {
                    $scopeRefs = collect($groupPhantomRanges)
                        ->flatten()
                        ->values()
                        ->all();
                }

                if (count($scopeRefs) > 0) {
                    $conditionalFormats[] = [
                        'sqref' => sprintf('%s%d', $this->columnLetter($cellColumn), $rowNumber),
                        'kind' => 'requirement_met',
                        'target_count' => $itemText['target_count'],
                        'match_value' => $itemText['match_value'],
                        'scope_refs' => $scopeRefs,
                    ];
                }
            }
        }

        $classReferenceColumn = $visibleLastColumnIndex + 2;
        $classLabelColumn = $classReferenceColumn + 1;
        $classRoleColumn = $classReferenceColumn + 2;
        $columnWidths[$classReferenceColumn] = 10;
        $columnWidths[$classLabelColumn] = 18;
        $columnWidths[$classRoleColumn] = 18;

        $rows[2]['cells'][$classReferenceColumn] = ['value' => 'Classes', 'style' => 'group_header'];
        $rows[3]['cells'][$classReferenceColumn] = ['value' => 'Code', 'style' => 'group_header'];
        $rows[3]['cells'][$classLabelColumn] = ['value' => 'Job', 'style' => 'group_header'];
        $rows[3]['cells'][$classRoleColumn] = ['value' => 'Role', 'style' => 'group_header'];

        $classOptionsStartRow = 4;

        foreach ($payload['class_options'] as $index => $option) {
            $rowNumber = $classOptionsStartRow + $index;
            $rows[$rowNumber]['cells'][$classReferenceColumn] = ['value' => $option['value'], 'style' => 'reference_value'];
            $rows[$rowNumber]['cells'][$classLabelColumn] = ['value' => $option['label'], 'style' => 'reference_value'];
            $rows[$rowNumber]['cells'][$classRoleColumn] = ['value' => $option['role'], 'style' => 'reference_value'];
        }

        $classOptionsEndRow = max($classOptionsStartRow, $classOptionsStartRow + count($payload['class_options']) - 1);
        $classValidationFormula = sprintf(
            '%s!$%s$%d:$%s$%d',
            self::SHEET_NAME,
            $this->columnLetter($classReferenceColumn),
            $classOptionsStartRow,
            $this->columnLetter($classReferenceColumn),
            $classOptionsEndRow,
        );

        if ($payload['has_phantom_jobs']) {
            $phantomReferenceColumn = $classReferenceColumn + 4;
            $columnWidths[$phantomReferenceColumn] = 18;
            $rows[2]['cells'][$phantomReferenceColumn] = ['value' => 'Phantom Jobs', 'style' => 'group_header'];
            $rows[3]['cells'][$phantomReferenceColumn] = ['value' => 'Name', 'style' => 'group_header'];

            $phantomOptionsStartRow = 4;

            foreach ($payload['phantom_job_options'] as $index => $option) {
                $rowNumber = $phantomOptionsStartRow + $index;
                $rows[$rowNumber]['cells'][$phantomReferenceColumn] = ['value' => $option['value'], 'style' => 'reference_value'];
            }

            $phantomOptionsEndRow = max($phantomOptionsStartRow, $phantomOptionsStartRow + count($payload['phantom_job_options']) - 1);
            $phantomValidationFormula = sprintf(
                '%s!$%s$%d:$%s$%d',
                self::SHEET_NAME,
                $this->columnLetter($phantomReferenceColumn),
                $phantomOptionsStartRow,
                $this->columnLetter($phantomReferenceColumn),
                $phantomOptionsEndRow,
            );
        } else {
            $phantomValidationFormula = null;
        }

        return [
            'rows' => $rows,
            'merges' => $merges,
            'data_validations' => collect($dataValidations)
                ->map(function (array $validation) use ($classValidationFormula, $phantomValidationFormula) {
                    $formula = match ($validation['option_type']) {
                        'class' => $classValidationFormula,
                        'phantom' => $phantomValidationFormula,
                        default => null,
                    };

                    if (! filled($formula)) {
                        return null;
                    }

                    return [
                        'sqref' => $validation['sqref'],
                        'formula' => $formula,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
            'conditional_formats' => collect($conditionalFormats)
                ->map(function (array $conditionalFormat) use ($classReferenceColumn, $classRoleColumn, $classOptionsStartRow, $classOptionsEndRow) {
                    if ($conditionalFormat['kind'] === 'class_role') {
                        $lookupRange = sprintf(
                            '$%s$%d:$%s$%d',
                            $this->columnLetter($classReferenceColumn),
                            $classOptionsStartRow,
                            $this->columnLetter($classRoleColumn),
                            $classOptionsEndRow,
                        );

                        return [
                            'sqref' => $conditionalFormat['sqref'],
                            'rules' => [
                                [
                                    'kind' => 'expression',
                                    'style' => 'tank_dynamic',
                                    'formula' => sprintf(
                                        'IFERROR(VLOOKUP($%s,%s,3,FALSE),"")="tank"',
                                        $conditionalFormat['class_cell'],
                                        $lookupRange,
                                    ),
                                ],
                                [
                                    'kind' => 'expression',
                                    'style' => 'healer_dynamic',
                                    'formula' => sprintf(
                                        'IFERROR(VLOOKUP($%s,%s,3,FALSE),"")="healer"',
                                        $conditionalFormat['class_cell'],
                                        $lookupRange,
                                    ),
                                ],
                                [
                                    'kind' => 'expression',
                                    'style' => 'dps_dynamic',
                                    'formula' => sprintf(
                                        'OR(IFERROR(VLOOKUP($%s,%s,3,FALSE),"")="melee dps",IFERROR(VLOOKUP($%s,%s,3,FALSE),"")="physical ranged dps",IFERROR(VLOOKUP($%s,%s,3,FALSE),"")="magic ranged dps")',
                                        $conditionalFormat['class_cell'],
                                        $lookupRange,
                                        $conditionalFormat['class_cell'],
                                        $lookupRange,
                                        $conditionalFormat['class_cell'],
                                        $lookupRange,
                                    ),
                                ],
                            ],
                        ];
                    }

                    if ($conditionalFormat['kind'] === 'requirement_met') {
                        $countFormula = collect($conditionalFormat['scope_refs'])
                            ->map(fn (string $cellRef) => sprintf('COUNTIF($%s,"%s")', $cellRef, $conditionalFormat['match_value']))
                            ->implode('+');

                        return [
                            'sqref' => $conditionalFormat['sqref'],
                            'rules' => [[
                                'kind' => 'expression',
                                'style' => 'requirement_met',
                                'formula' => sprintf(
                                    '(%s)>=%d',
                                    $countFormula,
                                    $conditionalFormat['target_count'],
                                ),
                            ]],
                        ];
                    }

                    return null;
                })
                ->filter()
                ->values()
                ->all(),
            'column_widths' => $columnWidths,
            'last_column' => $lastColumn,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStylesXml(): array
    {
        $themes = [
            'tank' => ['label' => '9EC0DD', 'cell' => 'DCE9F5', 'selector' => 'C5DBED'],
            'healer' => ['label' => 'AED28B', 'cell' => 'DDEFCB', 'selector' => 'C5DFAA'],
            'melee' => ['label' => 'D28A20', 'cell' => 'EFC17E', 'selector' => 'E2A24A'],
            'physical_ranged' => ['label' => 'D55B2E', 'cell' => 'EFAA8F', 'selector' => 'DF7A53'],
            'magic_ranged' => ['label' => 'A48BC7', 'cell' => 'E0D7EE', 'selector' => 'C0B0D9'],
            'dps' => ['label' => 'D36B7A', 'cell' => 'F0C0C7', 'selector' => 'E0959F'],
            'bench' => ['label' => 'CEBCA1', 'cell' => 'EFE5D6', 'selector' => 'DFD1BD'],
            'neutral' => ['label' => 'D4DCE4', 'cell' => 'F1F4F7', 'selector' => 'E4E9EE'],
        ];

        $fills = [
            ['type' => 'none'],
            ['type' => 'gray125'],
            ['type' => 'solid', 'color' => 'F7E8CC'],
            ['type' => 'solid', 'color' => 'F6DFCA'],
        ];

        $fillIndexes = [];

        foreach ($themes as $themeKey => $colors) {
            foreach ($colors as $variant => $color) {
                $fills[] = ['type' => 'solid', 'color' => $color];
                $fillIndexes[$themeKey.'_'.$variant] = count($fills) - 1;
            }
        }

        $styleMap = [];
        $cellXfs = [
            ['font' => 0, 'fill' => 0, 'align' => null],
            ['font' => 2, 'fill' => 2, 'align' => ['horizontal' => 'center', 'vertical' => 'center', 'wrap' => true]],
            ['font' => 1, 'fill' => 3, 'align' => ['horizontal' => 'center', 'vertical' => 'center', 'wrap' => true]],
        ];

        $styleMap['default'] = 0;
        $styleMap['title'] = 1;
        $styleMap['group_header'] = 2;
        $cellXfs[] = [
            'font' => 0,
            'fill' => 0,
            'align' => ['horizontal' => 'left', 'vertical' => 'center', 'wrap' => false],
        ];
        $styleMap['reference_value'] = count($cellXfs) - 1;

        foreach (array_keys($themes) as $themeKey) {
            $cellXfs[] = [
                'font' => 1,
                'fill' => $fillIndexes[$themeKey.'_label'],
                'align' => ['horizontal' => 'center', 'vertical' => 'center', 'wrap' => true],
            ];
            $styleMap[$themeKey.'_label'] = count($cellXfs) - 1;

            $cellXfs[] = [
                'font' => 0,
                'fill' => $fillIndexes[$themeKey.'_cell'],
                'align' => ['horizontal' => 'center', 'vertical' => 'center', 'wrap' => true],
            ];
            $styleMap[$themeKey.'_name'] = count($cellXfs) - 1;

            $cellXfs[] = [
                'font' => 1,
                'fill' => $fillIndexes[$themeKey.'_selector'],
                'align' => ['horizontal' => 'center', 'vertical' => 'center', 'wrap' => true],
            ];
            $styleMap[$themeKey.'_selector'] = count($cellXfs) - 1;
        }

        $dxfMap = [
            'tank_dynamic' => 0,
            'healer_dynamic' => 1,
            'dps_dynamic' => 2,
            'requirement_met' => 3,
        ];
        $dxfsXml = implode('', [
            '<dxf><fill><patternFill patternType="solid"><fgColor rgb="FFDCE9F5"/><bgColor rgb="FFDCE9F5"/></patternFill></fill></dxf>',
            '<dxf><fill><patternFill patternType="solid"><fgColor rgb="FFDDEFCB"/><bgColor rgb="FFDDEFCB"/></patternFill></fill></dxf>',
            '<dxf><fill><patternFill patternType="solid"><fgColor rgb="FFF0C0C7"/><bgColor rgb="FFF0C0C7"/></patternFill></fill></dxf>',
            '<dxf><fill><patternFill patternType="solid"><fgColor rgb="FFCDE8B5"/><bgColor rgb="FFCDE8B5"/></patternFill></fill></dxf>',
        ]);

        $fontsXml = implode('', [
            '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>',
            '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>',
            '<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>',
        ]);

        $fillsXml = implode('', array_map(function (array $fill) {
            if ($fill['type'] === 'none') {
                return '<fill><patternFill patternType="none"/></fill>';
            }

            if ($fill['type'] === 'gray125') {
                return '<fill><patternFill patternType="gray125"/></fill>';
            }

            return sprintf(
                '<fill><patternFill patternType="solid"><fgColor rgb="FF%s"/><bgColor indexed="64"/></patternFill></fill>',
                $fill['color']
            );
        }, $fills));

        $bordersXml = '<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border>';

        $cellXfsXml = implode('', array_map(function (array $xf) {
            $alignment = $xf['align'];

            if ($alignment === null) {
                return sprintf(
                    '<xf numFmtId="0" fontId="%d" fillId="%d" borderId="0" xfId="0" applyBorder="1"/>',
                    $xf['font'],
                    $xf['fill'],
                );
            }

            return sprintf(
                '<xf numFmtId="0" fontId="%d" fillId="%d" borderId="0" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="%s" vertical="%s" wrapText="%d"/></xf>',
                $xf['font'],
                $xf['fill'],
                $alignment['horizontal'],
                $alignment['vertical'],
                $alignment['wrap'] ? 1 : 0,
            );
        }, $cellXfs));

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="3">{$fontsXml}</fonts>
  <fills count="{$this->xmlInt(count($fills))}">{$fillsXml}</fills>
  <borders count="1">{$bordersXml}</borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="{$this->xmlInt(count($cellXfs))}">{$cellXfsXml}</cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
  <dxfs count="4">{$dxfsXml}</dxfs>
</styleSheet>
XML;

        return [
            'xml' => $xml,
            'style_map' => $styleMap,
            'dxf_map' => $dxfMap,
        ];
    }

    /**
     * @param  array<string, mixed>  $sheet
     * @param  array<string, int>  $styleMap
     */
    private function buildWorksheetXml(array $sheet, array $styleMap, array $dxfMap = []): string
    {
        $columnsXml = implode('', array_map(function (int $index, float|int $width) {
            return sprintf(
                '<col min="%d" max="%d" width="%s" customWidth="1"/>',
                $index,
                $index,
                $this->xmlNumber($width),
            );
        }, array_keys($sheet['column_widths']), $sheet['column_widths']));

        ksort($sheet['rows']);

        $rowsXml = '';

        foreach ($sheet['rows'] as $rowIndex => $row) {
            ksort($row['cells']);

            $cellsXml = '';

            foreach ($row['cells'] as $columnIndex => $cell) {
                $styleIndex = $styleMap[$cell['style']] ?? $styleMap['default'];
                $reference = $this->columnLetter($columnIndex).$rowIndex;
                $value = (string) $cell['value'];

                if ($value === '') {
                    $cellsXml .= sprintf('<c r="%s" s="%d"/>', $reference, $styleIndex);

                    continue;
                }

                $cellsXml .= sprintf(
                    '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                    $reference,
                    $styleIndex,
                    $this->xmlText($value),
                );
            }

            $rowsXml .= sprintf(
                '<row r="%d" ht="%s" customHeight="1">%s</row>',
                $rowIndex,
                $this->xmlNumber($row['height'] ?? 18),
                $cellsXml,
            );
        }

        $mergeCellsXml = '';

        if (count($sheet['merges']) > 0) {
            $mergeCellsXml = sprintf(
                '<mergeCells count="%d">%s</mergeCells>',
                count($sheet['merges']),
                implode('', array_map(fn (string $merge) => sprintf('<mergeCell ref="%s"/>', $merge), $sheet['merges']))
            );
        }

        $dataValidationsXml = '';

        if (count($sheet['data_validations']) > 0) {
            $dataValidationsXml = sprintf(
                '<dataValidations count="%d">%s</dataValidations>',
                count($sheet['data_validations']),
                implode('', array_map(function (array $validation) {
                    return sprintf(
                        '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="%s"><formula1>%s</formula1></dataValidation>',
                        $validation['sqref'],
                        $this->xmlText($validation['formula']),
                    );
                }, $sheet['data_validations']))
            );
        }

        $conditionalFormattingXml = '';

        if (count($sheet['conditional_formats'] ?? []) > 0) {
            $priority = 1;
            $conditionalFormattingXml = implode('', array_map(function (array $conditionalFormat) use (&$priority, $dxfMap) {
                $rulesXml = implode('', array_map(function (array $rule) use (&$priority, $dxfMap) {
                    $dxfId = $dxfMap[$rule['style']] ?? 0;

                    return sprintf(
                        '<cfRule type="%s" dxfId="%d" priority="%d"><formula>%s</formula></cfRule>',
                        $rule['kind'],
                        $dxfId,
                        $priority++,
                        $this->xmlText($rule['formula']),
                    );
                }, $conditionalFormat['rules']));

                return sprintf(
                    '<conditionalFormatting sqref="%s">%s</conditionalFormatting>',
                    $conditionalFormat['sqref'],
                    $rulesXml,
                );
            }, $sheet['conditional_formats']));
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheetViews><sheetView workbookViewId="0"/></sheetViews>
  <sheetFormatPr defaultRowHeight="15"/>
  <cols>{$columnsXml}</cols>
  <sheetData>{$rowsXml}</sheetData>
  {$mergeCellsXml}
  {$conditionalFormattingXml}
  {$dataValidationsXml}
</worksheet>
XML;
    }

    private function buildContentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;
    }

    private function buildRootRelationshipsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
    }

    private function buildWorkbookXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="{$this->xmlAttribute(self::SHEET_NAME)}" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
    }

    private function buildWorkbookRelationshipsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function buildAppPropertiesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>FullParty</Application>
  <HeadingPairs>
    <vt:vector size="2" baseType="variant">
      <vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant>
      <vt:variant><vt:i4>1</vt:i4></vt:variant>
    </vt:vector>
  </HeadingPairs>
  <TitlesOfParts>
    <vt:vector size="1" baseType="lpstr">
      <vt:lpstr>Roster</vt:lpstr>
    </vt:vector>
  </TitlesOfParts>
</Properties>
XML;
    }

    private function buildCorePropertiesXml(): string
    {
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:creator>FullParty</dc:creator>
  <cp:lastModifiedBy>FullParty</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">{$timestamp}</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">{$timestamp}</dcterms:modified>
</cp:coreProperties>
XML;
    }

    /**
     * @param  array<int, array<string, mixed>>  $requirements
     * @return array<int, array{label: string, items: array<int, array{text: string, target_count: int, match_value: string, scope_type: string, scope_group_keys: array<int, string>}>}>
     */
    private function buildRequirementsSummary(array $requirements): array
    {
        return collect($requirements)
            ->filter(fn (mixed $requirement) => is_array($requirement))
            ->groupBy(function (array $requirement): string {
                if (($requirement['scope_type'] ?? 'all_slots') === 'all_slots') {
                    return 'Either';
                }

                $groupLabels = collect($requirement['scope_groups'] ?? [])
                    ->filter(fn (mixed $scopeGroup) => is_array($scopeGroup))
                    ->map(fn (array $scopeGroup) => $this->shortRequirementGroupLabel(
                        is_array($scopeGroup['label'] ?? null) ? $scopeGroup['label'] : null,
                        (string) ($scopeGroup['key'] ?? '')
                    ))
                    ->filter(fn (string $label) => $label !== '')
                    ->values();

                if ($groupLabels->isNotEmpty()) {
                    return $groupLabels->implode('/');
                }

                return 'Other';
            })
            ->map(function (Collection $groupedRequirements, string $label): array {
                return [
                    'label' => $label,
                    'items' => $groupedRequirements
                        ->map(function (array $requirement): array {
                            $displayName = $this->cleanRequirementLabel(
                                $this->localizedText(
                                    is_array($requirement['item']['label'] ?? null)
                                        ? $requirement['item']['label']
                                        : null
                                )
                            );
                            $name = $this->cleanRequirementLabel(
                                $this->localizedText(
                                    is_array($requirement['item']['label'] ?? null)
                                        ? $requirement['item']['label']
                                        : null
                                )
                            );
                            $rawMatchValue = $this->localizedText(
                                is_array($requirement['item']['label'] ?? null)
                                    ? $requirement['item']['label']
                                    : null
                            );
                            $targetCount = max(1, (int) ($requirement['target_count'] ?? 1));

                            return [
                                'text' => trim(sprintf(
                                    '%d %s',
                                    $targetCount,
                                    $displayName !== '' ? $displayName : 'Unknown'
                                )),
                                'target_count' => $targetCount,
                                'match_value' => $rawMatchValue !== '' ? $rawMatchValue : ($name !== '' ? $name : 'Unknown'),
                                'scope_type' => (string) ($requirement['scope_type'] ?? 'all_slots'),
                                'scope_group_keys' => collect($requirement['scope_group_keys'] ?? [])
                                    ->filter(fn (mixed $groupKey) => is_string($groupKey) && $groupKey !== '')
                                    ->values()
                                    ->all(),
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function themeForSlot(?ActivitySlot $slot): string
    {
        if (! $slot) {
            return 'neutral';
        }

        if ($this->slotBench->isBench($slot)) {
            return 'bench';
        }

        $classField = $slot->fieldValues->firstWhere('source', 'character_classes');
        $classRole = $classField ? strtolower((string) ($this->resolveSlotFieldDisplayMeta($classField)['role'] ?? '')) : '';

        return match ($classRole) {
            'tank' => 'tank',
            'healer' => 'healer',
            'melee dps' => 'melee',
            'physical ranged dps' => 'physical_ranged',
            'magic ranged dps' => 'magic_ranged',
            default => $this->themeFromSlotText($slot),
        };
    }

    private function themeFromSlotText(ActivitySlot $slot): string
    {
        $normalized = Str::lower(trim(sprintf(
            '%s %s',
            $slot->slot_key,
            $this->localizedText($slot->slot_label),
        )));

        if (str_contains($normalized, 'tank')) {
            return 'tank';
        }

        if (str_contains($normalized, 'shield') || str_contains($normalized, 'regen') || str_contains($normalized, 'heal')) {
            return 'healer';
        }

        if (str_contains($normalized, 'melee')) {
            return 'melee';
        }

        if (str_contains($normalized, 'magic') || str_contains($normalized, 'caster')) {
            return 'magic_ranged';
        }

        if (str_contains($normalized, 'phys') || str_contains($normalized, 'range') || str_contains($normalized, 'ranged')) {
            return 'physical_ranged';
        }

        $hintRole = $slot->compositionHints
            ->sortBy('sort_order')
            ->pluck('role_key')
            ->first(fn (?string $role) => in_array($role, ['tank', 'healer', 'dps'], true));

        return match ($hintRole) {
            'tank' => 'tank',
            'healer' => 'healer',
            'dps' => 'dps',
            default => 'neutral',
        };
    }

    private function displayValueToString(?ActivitySlotFieldValue $fieldValue): string
    {
        if (! $fieldValue) {
            return '';
        }

        $displayValue = $this->resolveSlotFieldDisplayValue($fieldValue);

        if (is_string($displayValue)) {
            return trim($displayValue);
        }

        if (is_array($displayValue)) {
            $localized = $this->localizedText($displayValue);

            if ($localized !== '') {
                return $localized;
            }
        }

        return '';
    }

    /**
     * @param  array<string, string|null>|null  $value
     */
    private function localizedText(?array $value): string
    {
        if (! $value) {
            return '';
        }

        foreach ([app()->getLocale(), config('app.fallback_locale'), 'en'] as $locale) {
            if (is_string($locale) && filled($value[$locale] ?? null)) {
                return trim((string) $value[$locale]);
            }
        }

        foreach ($value as $translation) {
            if (filled($translation)) {
                return trim((string) $translation);
            }
        }

        return '';
    }

    private function cleanRequirementLabel(string $label): string
    {
        return trim((string) preg_replace('/^Phantom\s+/i', '', $label));
    }

    private function shortRequirementGroupLabel(?array $label, string $fallback): string
    {
        $resolved = $this->localizedText($label);
        $resolved = $resolved !== '' ? $resolved : $fallback;

        return trim((string) preg_replace('/^Party\s+/i', '', $resolved));
    }

    private function columnLetter(int $columnIndex): string
    {
        $letter = '';

        while ($columnIndex > 0) {
            $remainder = ($columnIndex - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $columnIndex = intdiv($columnIndex - 1, 26);
        }

        return $letter;
    }

    private function xmlText(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1);
    }

    private function xmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1);
    }

    private function xmlNumber(float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function xmlInt(int $value): string
    {
        return (string) $value;
    }
}
