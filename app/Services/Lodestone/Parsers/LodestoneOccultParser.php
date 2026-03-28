<?php

namespace App\Services\Lodestone\Parsers;

use App\DTOs\OccultData;
use App\DTOs\PhantomJobData;
use App\Exceptions\LodestoneParseException;
use DOMXPath;

/**
 * Parses Occult Crescent progression data from Lodestone class/job page.
 *
 * Parses from:
 * - Progression blocks: //div[contains(@class, 'character__job__list')]
 * - Phantom Jobs: //li[contains(@class, 'js__sp_job')]
 *
 * Extracts:
 * - Knowledge Level
 * - EXP progress text
 * - Sanguine Cipher count
 * - Phantom Job summaries (name, level, mastery, icon)
 *
 * EXCLUDES:
 * - Phantom Job actions/abilities/traits
 *
 * Returns OccultData DTO.
 */
class LodestoneOccultParser
{
    use ParsesLodestoneHtml;

    /**
     * Phantom job name normalization mapping.
     */
    private const PHANTOM_JOB_NAMES = [
        'phantomknight' => 'knight',
        'phantom knight' => 'knight',
        'phantommystic knight' => 'mystic_knight',
        'phantom mystic knight' => 'mystic_knight',
        'phantomthief' => 'thief',
        'phantom thief' => 'thief',
        'phantommonk' => 'monk',
        'phantom monk' => 'monk',
        'phantomtime mage' => 'time_mage',
        'phantom time mage' => 'time_mage',
        'phantomwhite mage' => 'white_mage',
        'phantom white mage' => 'white_mage',
        'phantomblack mage' => 'black_mage',
        'phantom black mage' => 'black_mage',
        'phantomsummoner' => 'summoner',
        'phantom summoner' => 'summoner',
        'phantomred mage' => 'red_mage',
        'phantom red mage' => 'red_mage',
        'phantomblue mage' => 'blue_mage',
        'phantom blue mage' => 'blue_mage',
        'phantomfreelancer' => 'freelancer',
        'phantom freelancer' => 'freelancer',
    ];

    /**
     * Parse Occult HTML into OccultData DTO.
     */
    public function parse(string $html): OccultData
    {
        $dom = $this->createDomDocument($html);
        $xpath = new DOMXPath($dom);

        // Parse Occult progression block (Knowledge Level)
        $knowledgeLevel = null;
        $expText = null;

        $occultBlock = $this->parseOccultBlock($xpath);
        if ($occultBlock) {
            $knowledgeLevel = $occultBlock['knowledge_level'];
            $expText = $occultBlock['exp_text'] ?? null;
        }

        // Parse Sanguine Cipher block
        $sanguineCipher = $this->parseSanguineCipherBlock($xpath);

        // Parse Phantom Jobs
        $phantomJobs = $this->parsePhantomJobs($xpath);

        return new OccultData(
            knowledgeLevel: $knowledgeLevel,
            expText: $expText,
            sanguineCipher: $sanguineCipher,
            phantomJobs: $phantomJobs
        );
    }

    /**
     * Parse Occult progression block (Knowledge Level).
     *
     * @return array{knowledge_level: int, exp_text?: string}|null
     */
    private function parseOccultBlock(DOMXPath $xpath): ?array
    {
        // Query all progression blocks
        $blockNodes = $this->queryXPath($xpath, "//div[contains(@class, 'character__job__list')]");

        if (!$blockNodes) {
            return null;
        }

        // Find Occult block by searching for "Knowledge Level" text
        foreach ($blockNodes as $blockNode) {
            $nameText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__name')]", $blockNode);

            if (!$nameText) {
                continue;
            }

            // Check if this is the Occult block
            if (stripos($nameText, 'knowledge') !== false) {
                // Extract level using exact selector
                $levelText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__level')]", $blockNode);

                if (!$levelText) {
                    continue;
                }

                $level = $this->extractNumeric($levelText);

                if ($level === null) {
                    continue;
                }

                $data = [
                    'knowledge_level' => $level,
                ];

                // Extract EXP text if available
                $expText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__exp')]", $blockNode);

                if ($expText) {
                    $data['exp_text'] = $expText;
                }

                return $data;
            }
        }

        return null;
    }

    /**
     * Parse Sanguine Cipher block.
     */
    private function parseSanguineCipherBlock(DOMXPath $xpath): ?int
    {
        // Query all progression blocks
        $blockNodes = $this->queryXPath($xpath, "//div[contains(@class, 'character__job__list')]");

        if (!$blockNodes) {
            return null;
        }

        // Find Sanguine Cipher block
        foreach ($blockNodes as $blockNode) {
            $nameText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__name')]", $blockNode);

            if (!$nameText) {
                continue;
            }

            // Check if this is the Sanguine Cipher block
            if (stripos($nameText, 'sanguine') !== false || stripos($nameText, 'cipher') !== false) {
                // Value appears in character__job__exp
                $valueText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__exp')]", $blockNode);

                if (!$valueText) {
                    continue;
                }

                $value = $this->extractNumeric($valueText);

                return $value;
            }
        }

        return null;
    }

    /**
     * Parse Phantom Jobs (summary only, no actions/abilities/traits).
     *
     * @return array<string, PhantomJobData>
     */
    private function parsePhantomJobs(DOMXPath $xpath): array
    {
        $phantomJobs = [];

        // Query all phantom job nodes using exact selector
        $phantomNodes = $this->queryXPath($xpath, "//li[contains(@class, 'js__sp_job')]");

        if (!$phantomNodes) {
            return $phantomJobs;
        }

        foreach ($phantomNodes as $phantomNode) {
            try {
                $phantomJobData = $this->parsePhantomJobNode($xpath, $phantomNode);

                if ($phantomJobData) {
                    $phantomJobs[$phantomJobData->abbreviation] = $phantomJobData;
                }
            } catch (\Exception $e) {
                // Skip individual phantom job parsing errors
                continue;
            }
        }

        return $phantomJobs;
    }

    /**
     * Parse a single phantom job node into DTO.
     */
    private function parsePhantomJobNode(DOMXPath $xpath, \DOMNode $phantomNode): ?PhantomJobData
    {
        // Extract name using exact selector
        $name = $this->extractText($xpath, ".//p[contains(@class, 'character__support_job__name')]", $phantomNode);

        if (!$name) {
            return null;
        }

        // Extract level using exact selector
        $levelText = $this->extractText($xpath, ".//p[contains(@class, 'character__support_job__level')]", $phantomNode);

        if (!$levelText) {
            return null;
        }

        $level = $this->extractNumeric($levelText);

        if ($level === null) {
            return null;
        }

        // Extract mastery flag using exact selector
        $masteryNode = $this->queryXPath($xpath, ".//p[contains(@class, 'character__support_job__master')]", $phantomNode);
        $mastered = $masteryNode && $masteryNode->length > 0;

        // Try to extract icon URL
        $iconUrl = $this->extractAttribute($xpath, ".//img", 'src', $phantomNode);

        if ($iconUrl) {
            $iconUrl = $this->normalizeImageUrl($iconUrl);
        }

        $abbreviation = $this->normalizePhantomJobName($name);

        return new PhantomJobData(
            name: $name,
            abbreviation: $abbreviation,
            level: $level,
            mastered: $mastered,
            iconUrl: $iconUrl
        );
    }

    /**
     * Normalize phantom job name to abbreviation.
     *
     * Example: "Phantom Knight" -> "knight", "Phantom Time Mage" -> "time_mage"
     */
    private function normalizePhantomJobName(string $jobName): string
    {
        $normalized = strtolower(trim($jobName));
        $normalized = str_replace(['-', '_'], ' ', $normalized);

        // Check mapping
        if (isset(self::PHANTOM_JOB_NAMES[$normalized])) {
            return self::PHANTOM_JOB_NAMES[$normalized];
        }

        // Fallback: remove "phantom" prefix and convert spaces to underscores
        $normalized = preg_replace('/^phantom\s+/i', '', $normalized);
        $normalized = str_replace(' ', '_', $normalized);

        return $normalized;
    }
}
