<?php

namespace App\Services\Lodestone;

use App\DTOs\LodestoneCharacterSearchResult;
use App\Exceptions\LodestoneParseException;
use DOMDocument;
use DOMNode;
use DOMXPath;

class LodestoneCharacterSearchService
{
    public function __construct(
        private readonly LodestoneHttpClient $httpClient,
    ) {}

    /**
     * @return array<int, LodestoneCharacterSearchResult>
     */
    public function search(string $characterName, string $worldName, int $limit = 20): array
    {
        $name = trim($characterName);
        $world = trim($worldName);

        if ($name === '' || $world === '' || $limit < 1) {
            return [];
        }

        $cachedResults = cache()->remember(
            $this->cacheKey($name, $world),
            now()->addHour(),
            fn (): array => $this->parseResults(
                $this->httpClient->fetch($this->searchUrl($name, $world)),
                PHP_INT_MAX,
            ),
        );

        return array_slice(
            array_map(
                fn (array $result): LodestoneCharacterSearchResult => $this->hydrateResult($result),
                array_values(array_filter($cachedResults, fn ($result): bool => is_array($result))),
            ),
            0,
            $limit,
        );
    }

    /**
     * @return array<int, string>
     */
    public function availableWorlds(): array
    {
        return array_values(config('lodestone_worlds.values', []));
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function worldOptions(): array
    {
        return collect($this->availableWorlds())
            ->map(fn (string $world) => [
                'label' => $world,
                'value' => $world,
            ])
            ->values()
            ->all();
    }

    private function searchUrl(string $characterName, string $worldName): string
    {
        return sprintf(
            '%s/character/?q=%s&worldname=%s',
            rtrim((string) config('lodestone.base_url'), '/'),
            urlencode($characterName),
            urlencode($worldName),
        );
    }

    private function cacheKey(string $characterName, string $worldName): string
    {
        $normalizedName = preg_replace('/\s+/', ' ', mb_strtolower(trim($characterName)));
        $normalizedWorld = preg_replace('/\s+/', ' ', mb_strtolower(trim($worldName)));

        return sprintf(
            'lodestone:character-search:v2:%s:%s',
            sha1((string) $normalizedName),
            sha1((string) $normalizedWorld),
        );
    }

    /**
     * @return array<int, array<string, string|null>>
     *
     * @throws LodestoneParseException
     */
    private function parseResults(string $html, int $limit): array
    {
        if (trim($html) === '') {
            throw LodestoneParseException::emptyResponse('character search');
        }

        $document = new DOMDocument();

        libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html);
        libxml_clear_errors();

        if ($loaded === false) {
            throw LodestoneParseException::malformedHtml('character search');
        }

        $xpath = new DOMXPath($document);
        $resultNodes = $xpath->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' entry__link ') and starts-with(@href, '/lodestone/character/')]");

        if ($resultNodes === false) {
            throw LodestoneParseException::invalidStructure('Unable to query character search result nodes.');
        }

        $resultsByLodestoneId = [];

        foreach ($resultNodes as $resultNode) {
            $result = $this->parseResultNode($xpath, $resultNode);

            if (!$result instanceof LodestoneCharacterSearchResult) {
                continue;
            }

            $resultsByLodestoneId[$result->lodestoneId] = $result->toArray();

            if (count($resultsByLodestoneId) >= $limit) {
                break;
            }
        }

        return array_values($resultsByLodestoneId);
    }

    private function parseResultNode(DOMXPath $xpath, DOMNode $resultNode): ?LodestoneCharacterSearchResult
    {
        $href = $resultNode->attributes?->getNamedItem('href')?->nodeValue ?? '';

        if (!preg_match('#/lodestone/character/(\d+)/#', $href, $matches)) {
            return null;
        }

        $lodestoneId = $matches[1];
        $name = trim((string) $xpath->evaluate("string(.//p[contains(concat(' ', normalize-space(@class), ' '), ' entry__name ')])", $resultNode));
        $worldLine = preg_replace(
            '/\s+/',
            ' ',
            trim((string) $xpath->evaluate("string(.//p[contains(concat(' ', normalize-space(@class), ' '), ' entry__world ')])", $resultNode))
        );

        if ($name === '' || !is_string($worldLine) || $worldLine === '') {
            return null;
        }

        $world = $worldLine;
        $dataCenter = null;

        if (preg_match('/^(.*?)\s*\[(.*?)\]$/', $worldLine, $worldMatches)) {
            $world = trim($worldMatches[1]);
            $dataCenter = trim($worldMatches[2]);
        }

        $avatarUrl = $xpath->evaluate("string(.//div[contains(concat(' ', normalize-space(@class), ' '), ' entry__chara__face ')]//img/@src)", $resultNode);
        $avatarUrl = is_string($avatarUrl) && trim($avatarUrl) !== '' ? trim($avatarUrl) : null;

        return new LodestoneCharacterSearchResult(
            lodestoneId: $lodestoneId,
            name: $name,
            world: $world,
            dataCenter: $dataCenter,
            avatarUrl: $avatarUrl,
            profileUrl: rtrim((string) config('lodestone.base_url'), '/')."/character/{$lodestoneId}/",
        );
    }

    /**
     * @param  array<string, string|null>  $result
     */
    private function hydrateResult(array $result): LodestoneCharacterSearchResult
    {
        return new LodestoneCharacterSearchResult(
            lodestoneId: (string) ($result['lodestone_id'] ?? ''),
            name: (string) ($result['name'] ?? ''),
            world: (string) ($result['world'] ?? ''),
            dataCenter: isset($result['datacenter']) ? (string) $result['datacenter'] : null,
            avatarUrl: isset($result['avatar_url']) ? (string) $result['avatar_url'] : null,
            profileUrl: (string) ($result['profile_url'] ?? ''),
        );
    }
}
