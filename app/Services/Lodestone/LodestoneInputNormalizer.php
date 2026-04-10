<?php

namespace App\Services\Lodestone;

use App\DTOs\LodestoneUrls;
use App\Exceptions\LodestoneInvalidInputException;

/**
 * Normalizes various Lodestone input formats into a validated Lodestone ID.
 *
 * Accepts:
 * - Numeric ID: "47431834"
 * - Full profile URL: "https://na.finalfantasyxiv.com/lodestone/character/47431834/"
 * - Class/job URL: "https://na.finalfantasyxiv.com/lodestone/character/47431834/class_job/"
 * - Other regional variants (eu, jp, fr, de)
 */
class LodestoneInputNormalizer
{
    /**
     * Normalize input to a LodestoneUrls DTO.
     *
     * @throws LodestoneInvalidInputException
     */
    public function normalize(string $input): LodestoneUrls
    {
        $lodestoneId = $this->extractLodestoneId($input);
        $this->validateLodestoneId($lodestoneId);

        return LodestoneUrls::fromLodestoneId($lodestoneId);
    }

    /**
     * Extract Lodestone ID from various input formats.
     *
     * @throws LodestoneInvalidInputException
     */
    public function extractLodestoneId(string $input): string
    {
        $input = trim($input);

        if (empty($input)) {
            throw LodestoneInvalidInputException::empty();
        }

        // If already numeric, return as-is
        if (ctype_digit($input)) {
            return $input;
        }

        // Try to extract from URL
        if ($this->isUrl($input)) {
            return $this->extractIdFromUrl($input);
        }

        throw LodestoneInvalidInputException::invalidFormat($input);
    }

    /**
     * Check if input is a URL.
     */
    private function isUrl(string $input): bool
    {
        return str_starts_with($input, 'http://') || str_starts_with($input, 'https://');
    }

    /**
     * Extract Lodestone ID from URL.
     *
     * Supports various URL formats:
     * - https://na.finalfantasyxiv.com/lodestone/character/47431834/
     * - https://eu.finalfantasyxiv.com/lodestone/character/47431834/class_job/
     * - https://jp.finalfantasyxiv.com/lodestone/character/47431834/minion/
     *
     * @throws LodestoneInvalidInputException
     */
    private function extractIdFromUrl(string $url): string
    {
        // Pattern: /character/{ID}/ or /character/{ID}
        if (preg_match('#/character/(\d+)/?#', $url, $matches)) {
            return $matches[1];
        }

        throw LodestoneInvalidInputException::invalidFormat($url);
    }

    /**
     * Validate that the extracted Lodestone ID is valid.
     *
     * @throws LodestoneInvalidInputException
     */
    private function validateLodestoneId(string $lodestoneId): void
    {
        if (!ctype_digit($lodestoneId)) {
            throw LodestoneInvalidInputException::notNumeric($lodestoneId);
        }

        // Lodestone IDs are typically 8 digits, but allow flexibility
        if (strlen($lodestoneId) < 6 || strlen($lodestoneId) > 12) {
            throw LodestoneInvalidInputException::invalidFormat($lodestoneId);
        }
    }
}
