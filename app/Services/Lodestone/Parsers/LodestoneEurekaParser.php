<?php

namespace App\Services\Lodestone\Parsers;

use App\DTOs\EurekaData;
use App\Exceptions\LodestoneParseException;
use DOMXPath;

/**
 * Parses Eureka progression data from Lodestone class/job page.
 *
 * Parses from: //div[contains(@class, 'character__job__list')]
 *
 * Extracts:
 * - Elemental Level
 * - EXP progress text
 *
 * Returns EurekaData DTO or null if not found.
 */
class LodestoneEurekaParser
{
    use ParsesLodestoneHtml;

    /**
     * Parse Eureka HTML into EurekaData DTO.
     */
    public function parse(string $html): ?EurekaData
    {
        $dom = $this->createDomDocument($html);
        $xpath = new DOMXPath($dom);

        // Query all progression blocks using exact selector
        $blockNodes = $this->queryXPath($xpath, "//div[contains(@class, 'character__job__list')]");

        if (!$blockNodes) {
            return null;
        }

        // Find Eureka block by searching for "Elemental Level" text
        foreach ($blockNodes as $blockNode) {
            $nameText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__name')]", $blockNode);

            if (!$nameText) {
                continue;
            }

            // Check if this is the Eureka block
            if (stripos($nameText, 'elemental') !== false) {
                return $this->parseEurekaBlock($xpath, $blockNode);
            }
        }

        return null;
    }

    /**
     * Parse Eureka block node into DTO.
     */
    private function parseEurekaBlock(DOMXPath $xpath, \DOMNode $blockNode): ?EurekaData
    {
        // Extract level using exact selector
        $levelText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__level')]", $blockNode);

        if (!$levelText) {
            return null;
        }

        $level = $this->extractNumeric($levelText);

        if ($level === null) {
            return null;
        }

        // Extract EXP text if available using exact selector
        $expText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__exp')]", $blockNode);

        return new EurekaData(
            elementalLevel: $level,
            expText: $expText
        );
    }
}
