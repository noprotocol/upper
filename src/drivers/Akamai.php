<?php

namespace ostark\upper\drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use ostark\upper\exceptions\AkamaiApiException;

/**
 * Class Akamai Driver
 *
 * @package ostark\upper\drivers
 *
 */
class Akamai extends AbstractPurger implements CachePurgeInterface
{
    /**
     * Both networks are invalidated, so staged content is purged as well
     */
    private const ENVIRONMENTS = ['production', 'staging'];

    /**
     * Akamai rejects purge bodies larger than 50 KB
     */
    private const MAX_BODY_BYTES = 50000;

    /**
     * @var string
     */
    public $host;

    /**
     * @var string
     */
    public $clientToken;

    /**
     * @var string
     */
    public $clientSecret;

    /**
     * @var string
     */
    public $accessToken;

    /**
     * @var string
     */
    public $maxSize;

    /**
     * Purge cache by tag
     *
     * @param string $tag
     *
     * @return bool
     */
    public function purgeTag(string $tag)
    {
        if ($this->useLocalTags) {
            return $this->purgeUrlsByTag($tag);
        }

        $result = true;

        foreach (self::ENVIRONMENTS as $environment) {
            foreach ($this->chunkObjects([$tag]) as $chunk) {
                $result = $this->sendRequest($environment, 'tag', $chunk) && $result;
            }
        }

        return $result;
    }

    /**
     * Purge cache by urls
     *
     * @param string[] $urls absolute urls; relative ones are skipped
     *
     * @return bool
     */
    public function purgeUrls(array $urls)
    {
        // Akamai accepts multiple objects per request, and each object counts
        // towards a rate limit, so send one request instead of one per url.
        $urls = $this->absoluteUrls($urls);

        if (count($urls) === 0) {
            return true;
        }

        $result = true;

        foreach (self::ENVIRONMENTS as $environment) {
            foreach ($this->chunkObjects($urls) as $chunk) {
                $result = $this->sendRequest($environment, 'url', $chunk) && $result;
            }
        }

        return $result;
    }


    /**
     * Purge entire cache
     *
     * @return bool
     */
    public function purgeAll()
    {
        // TODO: Purge all in Akamai
        return true;
        // return $this->sendRequest('POST', 'purge_all');
    }

    /**
     * Send API call
     *
     * @param string   $environment
     * @param string   $type    of purge (tag|url)
     * @param string[] $objects tags or urls to invalidate
     *
     * @return bool
     * @throws \ostark\upper\exceptions\AkamaiApiException
     */
    protected function sendRequest(string $environment, string $type, array $objects): bool
    {
        // Akamai Open Edgegrid reads $_ENV which doesn't get populated by Craft, so filling in the blanks
        $_ENV['AKAMAI_HOST'] = getenv('AKAMAI_HOST');
        $_ENV['AKAMAI_CLIENT_TOKEN'] = getenv('AKAMAI_CLIENT_TOKEN');
        $_ENV['AKAMAI_CLIENT_SECRET'] = getenv('AKAMAI_CLIENT_SECRET');
        $_ENV['AKAMAI_ACCESS_TOKEN'] = getenv('AKAMAI_ACCESS_TOKEN');

        // EdgeGrid stores this as-is (it only trim()s) and passes it straight into
        // substr() when signing. An unset/empty variable must stay unset, otherwise
        // substr() gets a non-numeric string and throws a TypeError on PHP 8.
        $maxSize = getenv('AKAMAI_MAX_SIZE');
        $_ENV['AKAMAI_MAX_SIZE'] = ($maxSize === false || $maxSize === '') ? null : (int)$maxSize;

        $auth = \Akamai\Open\EdgeGrid\Authentication::createFromEnv();

        $auth->setHttpMethod('POST');
        $auth->setPath('/ccu/v3/invalidate/' . $type . '/' . $environment);

        $body = json_encode([
            'objects' => array_values($objects),
        ]);

        $auth->setBody($body);

        $url = 'https://' . $auth->getHost() . $auth->getPath();

        try {
            // http_errors is disabled so the response can be inspected below
            $response = (new Client(['http_errors' => false]))->request('POST', $url, [
                'headers' => [
                    'Authorization' => $auth->createAuthHeader(),
                    'Content-Type'  => 'application/json',
                ],
                'body' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new AkamaiApiException("Request to '$url' failed: " . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 429) {
            // Rate limited. Akamai doesn't consume tokens for denied requests, so
            // a later change will purge again. Never throw, the other environment
            // (or the next object) can still be purged.
            \Craft::warning(
                "Akamai rate limit exceeded while purging $type for $environment."
                . " The purge will be retried on the next change. Response: " . (string)$response->getBody(),
                'upper'
            );

            return false;
        }

        if ($status >= 400) {
            \Craft::warning(
                "Akamai rejected a $type purge for $environment with status $status. Response: " . (string)$response->getBody(),
                'upper'
            );

            throw AkamaiApiException::create(new Request('POST', $url), $response);
        }

        return true;
    }

    /**
     * Deduplicate urls and keep only the absolute ones Akamai expects.
     *
     * Url purging is hostname specific: a url has to match the cached object
     * exactly, scheme and hostname included, so relative urls are skipped with a
     * warning instead of being prefixed with DEFAULT_SITE_URL — that would only
     * ever match objects on that single hostname.
     *
     * @param string[] $urls
     *
     * @return string[]
     */
    private function absoluteUrls(array $urls): array
    {
        $resolved = [];

        foreach ($urls as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }

            if (!preg_match('#^https?://#i', $url)) {
                \Craft::warning(
                    "Skipped purge of '$url': only absolute urls (scheme and hostname) can be purged.",
                    'upper'
                );

                continue;
            }

            $resolved[] = $url;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Split objects into requests that stay within the body size Akamai signs.
     *
     * Akamai rejects bodies over 50 KB (413), and EdgeGrid signs only the first
     * AKAMAI_MAX_SIZE bytes of a body, so an oversized body would fail the
     * signature. Both limits are respected here.
     *
     * @param string[] $objects
     *
     * @return string[][]
     */
    private function chunkObjects(array $objects): array
    {
        $maxBytes = self::MAX_BODY_BYTES;

        if ($configured = (int)getenv('AKAMAI_MAX_SIZE')) {
            $maxBytes = min($maxBytes, $configured);
        }

        $chunks = [];
        $chunk = [];

        foreach ($objects as $object) {
            $candidate = array_merge($chunk, [$object]);

            if ($chunk && strlen(json_encode(['objects' => $candidate])) > $maxBytes) {
                $chunks[] = $chunk;
                $chunk = [$object];
                continue;
            }

            $chunk = $candidate;
        }

        if ($chunk) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }
}
