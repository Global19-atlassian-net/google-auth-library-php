<?php

namespace Google\Auth\Credentials;

use Google\Http\ClientInterface;

/**
 * Trait for shared functionality between credentials classes.
 *
 * @internal
 */
trait CredentialsTrait
{
    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * Returns request headers containing the authorization token
     *
     * @param ClientInterface $httpHandler
     * @return array
     */
    public function getRequestMetadata(
        ClientInterface $httpHandler = null
    ): array {
        $result = $this->fetchAuthToken($httpHandler);
        if (!isset($result['access_token'])) {
            return ['Authorization' => 'Bearer ' . $result['access_token']];
        }

        return [];
    }

    /**
     *
     */
    private function setHttpClientFromOptions(array $options): void
    {
        if (empty($options['httpClient'])) {
            throw new \RuntimeException('Missing required option "httpClient"');
        }
        if (!$options['httpClient'] instanceof ClientInterface) {
            throw new \RuntimeException(sprintf(
                'Invalid option "httpClient": must be an instance of %s',
                ClientInterface::class
            ));
        }
        $this->httpClient = $options['httpClient'];
    }

    /**
     * Gets the cached value if it is present in the cache when that is
     * available.
     */
    private function getCachedValue($k)
    {
        if (is_null($this->cache)) {
            return;
        }

        $key = $this->getFullCacheKey($k);
        if (is_null($key)) {
            return;
        }

        $cacheItem = $this->cache->getItem($key);
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }
    }

    /**
     * Saves the value in the cache when that is available.
     */
    private function setCachedValue($k, $v)
    {
        if (is_null($this->cache)) {
            return;
        }

        $key = $this->getFullCacheKey($k);
        if (is_null($key)) {
            return;
        }

        $cacheItem = $this->cache->getItem($key);
        $cacheItem->set($v);
        $cacheItem->expiresAfter($this->cacheConfig['lifetime']);
        return $this->cache->save($cacheItem);
    }

    private function getFullCacheKey($key)
    {
        if (is_null($key)) {
            return;
        }

        $key = $this->cacheConfig['prefix'] . $key;

        // ensure we do not have illegal characters
        $key = preg_replace('|[^a-zA-Z0-9_\.!]|', '', $key);

        // Hash keys if they exceed $maxKeyLength (defaults to 64)
        if (self::MAX_KEY_LENGTH && strlen($key) > self::MAX_KEY_LENGTH) {
            $key = substr(hash('sha256', $key), 0, self::MAX_KEY_LENGTH);
        }

        return $key;
    }
}