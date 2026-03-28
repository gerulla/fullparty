<?php

namespace App\Services\Lodestone\Parsers;

use App\Exceptions\LodestoneParseException;

/**
 * Orchestrating parser for the Lodestone class/job page.
 *
 * Composes specialized parsers:
 * - LodestoneMainJobsParser: Combat jobs + crafters/gatherers
 * - LodestoneEurekaParser: Eureka progression
 * - LodestoneBozjaParser: Bozja/Resistance progression
 * - LodestoneOccultParser: Occult Crescent + Phantom Jobs
 *
 * Returns normalized key-value pairs for all data.
 *
 * Example output:
 * [
 *     'job.pld.level' => 100,
 *     'job.war.level' => 85,
 *     'progression.eureka.elemental_level' => 60,
 *     'progression.bozja.resistance_rank' => 25,
 *     'progression.occult.knowledge_level' => 100,
 *     'phantom.knight.level' => 100,
 *     ...
 * ]
 */
class LodestoneClassJobParser
{
    public function __construct(
        private LodestoneMainJobsParser $mainJobsParser,
        private LodestoneEurekaParser $eurekaParser,
        private LodestoneBozjaParser $bozjaParser,
        private LodestoneOccultParser $occultParser,
    ) {}

    /**
     * Parse class/job HTML into normalized key-value data.
     *
     * @return array<string, mixed>
     * @throws LodestoneParseException
     */
    public function parse(string $html): array
    {
        if (empty($html)) {
            throw LodestoneParseException::emptyResponse('class/job page');
        }

        $allData = [];

        // Parse main jobs (combat + DoH/DoL)
        $mainJobsData = $this->mainJobsParser->parse($html);
        $allData = array_merge($allData, $mainJobsData->toNormalized());

        // Parse Eureka progression
        $eurekaData = $this->eurekaParser->parse($html);
        if ($eurekaData) {
            $allData = array_merge($allData, $eurekaData->toNormalized());
        }

        // Parse Bozja progression
        $bozjaData = $this->bozjaParser->parse($html);
        if ($bozjaData) {
            $allData = array_merge($allData, $bozjaData->toNormalized());
        }

        // Parse Occult Crescent + Phantom Jobs
        $occultData = $this->occultParser->parse($html);
        $allData = array_merge($allData, $occultData->toNormalized());

        return $allData;
    }
}
