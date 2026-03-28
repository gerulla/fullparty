<?php

namespace App\Services\Lodestone\Parsers;

use App\DTOs\BozjaData;
use App\Exceptions\LodestoneParseException;
use DOMXPath;

/**
 * Parses Bozja/Resistance progression data from Lodestone class/job page.
 *
 * Parses from: //div[contains(@class, 'character__job__list')]
 *
 * Extracts:
 * - Resistance Rank
 * - Current Mettle / Next Rank text
 *
 * Returns BozjaData DTO or null if not found.
 */
class LodestoneBozjaParser
{
    use ParsesLodestoneHtml;

    /**
     * Parse Bozja HTML into BozjaData DTO.
     */
    public function parse(string $html): ?BozjaData
    {
        $dom = $this->createDomDocument($html);
        $xpath = new DOMXPath($dom);

        // Query all progression blocks using exact selector
        $blockNodes = $this->queryXPath($xpath, "//div[contains(@class, 'character__job__list')]");

        if (!$blockNodes) {
            return null;
        }

        // Find Bozja block by searching for "Resistance Rank" text
        foreach ($blockNodes as $blockNode) {
            $nameText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__name')]", $blockNode);

            if (!$nameText) {
                continue;
            }

            // Check if this is the Bozja block
            if (stripos($nameText, 'resistance') !== false) {
                return $this->parseBozjaBlock($xpath, $blockNode);
            }
        }

        return null;
    }

    /**
     * Parse Bozja block node into DTO.
     */
    private function parseBozjaBlock(DOMXPath $xpath, \DOMNode $blockNode): ?BozjaData
    {
        // Extract rank using exact selector
        $rankText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__level')]", $blockNode);

        if (!$rankText) {
            return null;
        }

        $rank = $this->extractNumeric($rankText);

        if ($rank === null) {
            return null;
        }

        // Extract mettle text if available using exact selector
        $mettleText = $this->extractText($xpath, ".//div[contains(@class, 'character__job__exp')]", $blockNode);

        $currentMettle = null;
        $nextRankMettle = null;

        if ($mettleText) {
            // Try to parse current and next rank mettle from text
            // Expected format: "12,345 / 50,000" or similar
            if (preg_match('/([0-9,]+)\s*\/\s*([0-9,]+)/', $mettleText, $matches)) {
                $currentMettle = (int) str_replace(',', '', $matches[1]);
                $nextRankMettle = (int) str_replace(',', '', $matches[2]);
            }
        }

        return new BozjaData(
            resistanceRank: $rank,
            mettleText: $mettleText,
            currentMettle: $currentMettle,
            nextRankMettle: $nextRankMettle
        );
    }
}
