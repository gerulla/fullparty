<?php

namespace App\Services\Lodestone\Parsers;

use DOMXPath;

class LodestoneAchievementParser
{
    use ParsesLodestoneHtml;

    public function obtainedDate(string $html): ?string
    {
        $dom = $this->createDomDocument($html);
        $xpath = new DOMXPath($dom);

        $achievementText = $this->achievementScopedText($xpath);

        return $this->firstDate($achievementText)
            ?? $this->firstDate((string) $dom->textContent);
    }

    private function achievementScopedText(DOMXPath $xpath): string
    {
        $nodes = $this->queryXPath(
            $xpath,
            "//*[contains(translate(@class, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'achievement')]"
        );

        if (! $nodes) {
            return '';
        }

        $parts = [];

        foreach ($nodes as $node) {
            $parts[] = trim($node->textContent);
        }

        return implode(' ', array_filter($parts));
    }

    private function firstDate(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if (preg_match('/(?<!\d)(?:\d{4}[\/.-]\d{1,2}[\/.-]\d{1,2}|\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4})(?!\d)/u', $normalized, $matches)) {
            return $matches[0];
        }

        return null;
    }
}
