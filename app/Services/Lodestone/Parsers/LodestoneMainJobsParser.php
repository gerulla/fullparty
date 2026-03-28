<?php

namespace App\Services\Lodestone\Parsers;

use App\DTOs\JobData;
use App\DTOs\MainJobsData;
use App\Exceptions\LodestoneParseException;
use DOMXPath;

/**
 * Parses standard combat jobs, crafters, and gatherers from Lodestone class/job page.
 *
 * Parses from: //ul[contains(@class, 'character__job')]/li
 *
 * Returns MainJobsData DTO with all job information.
 */
class LodestoneMainJobsParser
{
    use ParsesLodestoneHtml;

    /**
     * Job abbreviation mapping (case-insensitive full name to abbreviation).
     */
    private const JOB_ABBREVIATIONS = [
        // Tanks
        'paladin' => 'pld',
        'warrior' => 'war',
        'darkknight' => 'drk',
        'dark knight' => 'drk',
        'gunbreaker' => 'gnb',

        // Healers
        'whitemage' => 'whm',
        'white mage' => 'whm',
        'scholar' => 'sch',
        'astrologian' => 'ast',
        'sage' => 'sge',

        // Melee DPS
        'monk' => 'mnk',
        'pugilist' => 'pgl',
        'dragoon' => 'drg',
        'lancer' => 'lnc',
        'ninja' => 'nin',
        'rogue' => 'rog',
        'samurai' => 'sam',
        'reaper' => 'rpr',
        'viper' => 'vpr',

        // Physical Ranged DPS
        'bard' => 'brd',
        'archer' => 'arc',
        'machinist' => 'mch',
        'dancer' => 'dnc',

        // Magical Ranged DPS
        'blackmage' => 'blm',
        'black mage' => 'blm',
        'thaumaturge' => 'thm',
        'summoner' => 'smn',
        'arcanist' => 'acn',
        'redmage' => 'rdm',
        'red mage' => 'rdm',
        'pictomancer' => 'pct',
        'bluemage' => 'blu',
        'blue mage' => 'blu',

        // Crafters (DoH)
        'carpenter' => 'crp',
        'blacksmith' => 'bsm',
        'armorer' => 'arm',
        'goldsmith' => 'gsm',
        'leatherworker' => 'ltw',
        'weaver' => 'wvr',
        'alchemist' => 'alc',
        'culinarian' => 'cul',

        // Gatherers (DoL)
        'miner' => 'min',
        'botanist' => 'btn',
        'fisher' => 'fsh',
    ];

    /**
     * Parse main jobs HTML into MainJobsData DTO.
     */
    public function parse(string $html): MainJobsData
    {
        $dom = $this->createDomDocument($html);
        $xpath = new DOMXPath($dom);

        $jobs = [];

        // Query all job list items using exact selector provided
        $jobNodes = $this->queryXPath($xpath, "//ul[contains(@class, 'character__job')]/li");

        if (!$jobNodes) {
            // No jobs found - return empty DTO
            return new MainJobsData([]);
        }

        foreach ($jobNodes as $jobNode) {
            try {
                $jobData = $this->parseJobNode($xpath, $jobNode);

                if ($jobData) {
                    $jobs[$jobData->abbreviation] = $jobData;
                }
            } catch (\Exception $e) {
                // Skip individual job parsing errors
                continue;
            }
        }

        return new MainJobsData($jobs);
    }

    /**
     * Parse a single job node.
     */
    private function parseJobNode(DOMXPath $xpath, \DOMNode $jobNode): ?JobData
    {
        // Extract job name using exact selector
        $name = $this->extractText($xpath, ".//div[contains(@class, 'character__job__name')]", $jobNode);

        if (!$name) {
            return null;
        }

        // Extract job level using exact selector
        $levelText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__level')]", $jobNode);

        if (!$levelText) {
            return null;
        }

        $level = $this->extractNumeric($levelText);

        if ($level === null) {
            return null;
        }

        $abbreviation = $this->normalizeJobName($name);

        return new JobData(
            name: $name,
            abbreviation: $abbreviation,
            level: $level
        );
    }

    /**
     * Normalize job name to standard abbreviation.
     *
     * Example: "Paladin" -> "pld", "White Mage" -> "whm"
     */
    private function normalizeJobName(string $jobName): string
    {
        $normalized = strtolower(trim($jobName));
        $normalized = str_replace([' ', '-', '_'], '', $normalized);

        return self::JOB_ABBREVIATIONS[$normalized] ?? $normalized;
    }
}
