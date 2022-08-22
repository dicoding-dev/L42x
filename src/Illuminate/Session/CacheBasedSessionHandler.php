<?php namespace Illuminate\Session;

use Illuminate\Cache\Repository;

class CacheBasedSessionHandler implements \SessionHandlerInterface {

	/**
	 * The cache repository instance.
	 */
	protected Repository $cache;

	/**
	 * The number of minutes to store the data in the cache.
	 */
	protected int $minutes;

	/**
	 * Create a new cache driven handler instance.
	 *
	 * @param  \Illuminate\Cache\Repository  $cache
	 * @param  int  $minutes
	 * @return void
	 */
	public function __construct(Repository $cache, $minutes)
	{
		$this->cache = $cache;
		$this->minutes = $minutes;
	}

	/**
	 * {@inheritDoc}
	 */
	public function open(string $path, string $name): bool
	{
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function close(): bool
	{
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function read(string $id): string|false
	{
		return $this->cache->get($id, '');
	}

	/**
	 * {@inheritDoc}
	 */
	public function write(string $id, string $data): bool
	{
		try {
            $this->cache->put($id, $data, $this->minutes);
        } catch (\Throwable) {
            return false;
        }

        return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function destroy(string $id): bool
	{
		try {
            $this->cache->forget($id);
        } catch (\Throwable) {
            return false;
        }

        return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function gc(int $max_lifetime): int|false
	{
		return 1;
	}

	/**
	 * Get the underlying cache repository.
	 */
	public function getCache(): Repository
    {
		return $this->cache;
	}

}
