<?php

namespace App\Services\Lodestone;

use App\Exceptions\LodestoneFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for fetching Lodestone pages.
 *
 * Responsible ONLY for downloading HTML content.
 * Does not parse or process the data.
 */
class LodestoneHttpClient
{
    private int $timeout;
    private int $retryTimes;
    private int $retrySleep;
    private string $userAgent;

    public function __construct()
    {
        $this->timeout = config('lodestone.http.timeout', 30);
        $this->retryTimes = config('lodestone.http.retry_times', 3);
        $this->retrySleep = config('lodestone.http.retry_sleep', 1000);
        $this->userAgent = config('lodestone.http.user_agent', 'Laravel Lodestone Scraper');
    }

    /**
     * Fetch HTML content from a URL.
     *
     * @throws LodestoneFetchException
     */
    public function fetch(string $url): string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ])
                ->timeout($this->timeout)
                ->retry(
                    times: $this->retryTimes,
                    sleepMilliseconds: $this->retrySleep,
                    when: fn ($exception) => $exception instanceof ConnectionException
                )
                ->get($url);

            // Handle HTTP error status codes
            if ($response->status() === 404) {
                throw LodestoneFetchException::characterNotFound(
                    $this->extractLodestoneIdFromUrl($url)
                );
            }

            if ($response->failed()) {
                throw LodestoneFetchException::httpError($url, $response->status());
            }

            $html = $response->body();

            if (empty($html)) {
                throw LodestoneFetchException::httpError($url, $response->status());
            }

            return $html;

        } catch (RequestException $e) {
            throw LodestoneFetchException::httpError(
                $url,
                $e->response?->status() ?? 0,
                $e
            );
        } catch (ConnectionException $e) {
            throw LodestoneFetchException::networkError($url, $e);
        } catch (\Exception $e) {
            if ($e instanceof LodestoneFetchException) {
                throw $e;
            }
            throw LodestoneFetchException::networkError($url, $e);
        }
    }

    /**
     * Fetch multiple URLs in parallel (optional optimization).
     *
     * @param array<string> $urls
     * @return array<string, string> URL => HTML content
     * @throws LodestoneFetchException
     */
    public function fetchMultiple(array $urls): array
    {
        $responses = Http::pool(fn ($pool) => array_map(
            fn ($url) => $pool->withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout($this->timeout)->get($url),
            $urls
        ));

        $results = [];
        foreach ($urls as $index => $url) {
            $response = $responses[$index];

            if ($response->failed()) {
                throw LodestoneFetchException::httpError($url, $response->status());
            }

            $results[$url] = $response->body();
        }

        return $results;
    }

    /**
     * Extract Lodestone ID from URL for error messages.
     */
    private function extractLodestoneIdFromUrl(string $url): string
    {
        if (preg_match('#/character/(\d+)#', $url, $matches)) {
            return $matches[1];
        }
        return 'unknown';
    }
}
