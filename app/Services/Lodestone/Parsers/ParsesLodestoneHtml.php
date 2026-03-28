<?php

namespace App\Services\Lodestone\Parsers;

use App\Exceptions\LodestoneParseException;
use DOMDocument;
use DOMXPath;

/**
 * Shared trait for common DOM parsing operations.
 */
trait ParsesLodestoneHtml
{
    /**
     * Create DOMDocument from HTML.
     *
     * @throws LodestoneParseException
     */
    protected function createDomDocument(string $html): DOMDocument
    {
        if (empty($html)) {
            throw LodestoneParseException::emptyResponse('parser');
        }

        $dom = new DOMDocument();

        // Suppress warnings from malformed HTML
        libxml_use_internal_errors(true);

        if (!$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw LodestoneParseException::malformedHtml('parser HTML');
        }

        libxml_clear_errors();

        return $dom;
    }

    /**
     * Query XPath and return nodes or null.
     */
    protected function queryXPath(DOMXPath $xpath, string $query, ?\DOMNode $contextNode = null): ?\DOMNodeList
    {
        $nodes = $xpath->query($query, $contextNode);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes;
    }

    /**
     * Extract text content from XPath query.
     */
    protected function extractText(DOMXPath $xpath, string $query, ?\DOMNode $contextNode = null): ?string
    {
        $nodes = $this->queryXPath($xpath, $query, $contextNode);

        if (!$nodes) {
            return null;
        }

        $text = trim($nodes->item(0)->textContent);

        return !empty($text) ? $text : null;
    }

    /**
     * Extract attribute from XPath query.
     */
    protected function extractAttribute(DOMXPath $xpath, string $query, string $attribute, ?\DOMNode $contextNode = null): ?string
    {
        $nodes = $this->queryXPath($xpath, $query, $contextNode);

        if (!$nodes) {
            return null;
        }

        $value = $nodes->item(0)->getAttribute($attribute);

        return !empty($value) ? $value : null;
    }

    /**
     * Extract numeric value from text (removes non-numeric characters).
     */
    protected function extractNumeric(string $text): ?int
    {
        if (preg_match('/(\d+)/', $text, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Normalize image URL (handle protocol-relative URLs).
     */
    protected function normalizeImageUrl(string $url): string
    {
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return $url;
    }
}
