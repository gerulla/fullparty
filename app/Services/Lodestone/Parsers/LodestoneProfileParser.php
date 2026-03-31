<?php

namespace App\Services\Lodestone\Parsers;

use App\Exceptions\LodestoneParseException;
use DOMDocument;
use DOMXPath;

/**
 * Parses the main Lodestone character profile page.
 *
 * Extracts:
 * - Character name
 * - World
 * - Data center
 * - Avatar URL
 * - Portrait URL
 * - Other basic profile data
 *
 * IMPORTANT: DOM selectors are based on Lodestone structure as of 2024.
 * Square Enix may change the HTML structure at any time.
 * These selectors should be treated as BEST-EFFORT and may need updating.
 */
class LodestoneProfileParser
{
    /**
     * Parse profile HTML into structured data.
     *
     * @return array{
     *     name: string,
     *     world: string,
     *     data_center: string,
     *     avatar_url: string,
     *     portrait_url: ?string,
     *     extra_data: array<string, mixed>
     * }
     * @throws LodestoneParseException
     */
    public function parse(string $html): array
    {
        if (empty($html)) {
            throw LodestoneParseException::emptyResponse('profile');
        }

        $dom = $this->createDomDocument($html);
        $xpath = new DOMXPath($dom);

        return [
            'name' => $this->parseName($xpath),
            'world' => $this->parseWorld($xpath),
            'data_center' => $this->parseDataCenter($xpath),
            'avatar_url' => $this->parseAvatarUrl($xpath),
            'portrait_url' => $this->parsePortraitUrl($xpath),
			'bio' => $this->parseBio($xpath),
            'extra_data' => $this->parseExtraData($xpath),
        ];
    }

    /**
     * Create DOMDocument from HTML.
     *
     * @throws LodestoneParseException
     */
    private function createDomDocument(string $html): DOMDocument
    {
        $dom = new DOMDocument();

        // Suppress warnings from malformed HTML
        libxml_use_internal_errors(true);

        if (!$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw LodestoneParseException::malformedHtml('profile page');
        }

        libxml_clear_errors();

        return $dom;
    }

    /**
     * Parse character name.
     *
     * PLACEHOLDER SELECTOR - Verify against actual Lodestone HTML structure.
     *
     * @throws LodestoneParseException
     */
    private function parseName(DOMXPath $xpath): string
    {
        // Common selectors to try (in priority order)
        $selectors = [
            "//p[@class='frame__chara__name']",
            "//div[@class='character__name']//h1",
            "//div[@class='frame__chara__box']//p[@class='frame__chara__name']",
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                $name = trim($nodes->item(0)->textContent);
                if (!empty($name)) {
                    return $name;
                }
            }
        }

        throw LodestoneParseException::missingElement('character name', 'profile');
    }

    /**
     * Parse world and data center.
     *
     * PLACEHOLDER SELECTOR - Verify against actual Lodestone HTML structure.
     * Typically formatted as: "World [Data Center]"
     *
     * @throws LodestoneParseException
     */
    private function parseWorld(DOMXPath $xpath): string
    {
        $selectors = [
            "//p[@class='frame__chara__world']",
            "//div[@class='character__profile__data']//p[contains(@class, 'world')]",
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                $text = trim($nodes->item(0)->textContent);
                // Extract world name (before bracket or entire text)
                if (preg_match('/^([^\[\(]+)/', $text, $matches)) {
                    return trim($matches[1]);
                }
                if (!empty($text)) {
                    return $text;
                }
            }
        }

        throw LodestoneParseException::missingElement('world', 'profile');
    }

    /**
     * Parse data center.
     *
     * PLACEHOLDER SELECTOR - Verify against actual Lodestone HTML structure.
     * Typically formatted as: "World [Data Center]"
     *
     * @throws LodestoneParseException
     */
    private function parseDataCenter(DOMXPath $xpath): string
    {
        $selectors = [
            "//p[@class='frame__chara__world']",
            "//div[@class='character__profile__data']//p[contains(@class, 'world')]",
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                $text = trim($nodes->item(0)->textContent);
                // Extract data center (text in brackets)
                if (preg_match('/\[([^\]]+)\]/', $text, $matches)) {
                    return trim($matches[1]);
                }
            }
        }

        // Data center is often optional/derivable, so don't throw
        return '';
    }

    /**
     * Parse avatar URL.
     *
     * PLACEHOLDER SELECTOR - Verify against actual Lodestone HTML structure.
     *
     * @throws LodestoneParseException
     */
    private function parseAvatarUrl(DOMXPath $xpath): string
    {
        $selectors = [
            "//div[@class='frame__chara__face']//img",
            "//div[@class='character__profile__image']//img",
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                $src = $nodes->item(0)->getAttribute('src');
                if (!empty($src)) {
                    return $this->normalizeImageUrl($src);
                }
            }
        }

        throw LodestoneParseException::missingElement('avatar image', 'profile');
    }

    /**
     * Parse portrait URL (full character image).
     */
    private function parsePortraitUrl(DOMXPath $xpath): ?string
    {
        $selectors = [
            "//div[@class='character__detail__image']//a//img",
            "//div[@class='js__image_popup']//img",
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                $src = $nodes->item(0)->getAttribute('src');
                if (!empty($src)) {
                    return $this->normalizeImageUrl($src);
                }
            }
        }

        return null;
    }
	
	/**
	 * Parse the Character Profile / Bio / Self Introduction
	 */
	private function parseBio(DOMXPath $xpath): ?string
	{
		$selectors = [
			"//div[@class='character__selfintroduction']",
		];
		
		foreach ($selectors as $selector) {
			$nodes = $xpath->query($selector);
			if ($nodes && $nodes->length > 0) {
				$text = trim($nodes->item(0)->textContent);
				if (!empty($text)) {
					return $text;
				}
			}
		}
		return null;
	}

    /**
     * Parse additional profile data for extra_data array.
     *
     * Examples of what could be extracted:
     * - Title
     * - Free Company
     * - Race/Clan/Gender
     * - Grand Company
     * - City-state
     *
     * This is intentionally basic. Expand as needed.
     */
    private function parseExtraData(DOMXPath $xpath): array
    {
        $extraData = [];

        // Example: Parse title if present
        // PLACEHOLDER - adjust selectors based on actual HTML
        $titleNodes = $xpath->query("//p[@class='frame__chara__title']");
        if ($titleNodes && $titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
            if (!empty($title)) {
                $extraData['title'] = $title;
            }
        }

        // Add more parsing logic here as needed for your use case

        return $extraData;
    }

    /**
     * Normalize image URLs (handle protocol-relative URLs, etc.).
     */
    private function normalizeImageUrl(string $url): string
    {
        // Handle protocol-relative URLs
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return $url;
    }
}
