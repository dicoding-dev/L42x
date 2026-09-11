<?php namespace Illuminate\Cache;

use Closure;
use DateInterval;
use Carbon\Carbon;
use DateTimeInterface;

class TaggedCache implements StoreInterface {

	/**
	 * The cache store implementation.
	 *
	 * @var \Illuminate\Cache\StoreInterface
	 */
	protected $store;

	/**
	 * The tag set instance.
	 *
	 * @var \Illuminate\Cache\TagSet
	 */
	protected $tags;

	/**
	 * Create a new tagged cache instance.
	 *
	 * @param  \Illuminate\Cache\StoreInterface  $store
	 * @param  \Illuminate\Cache\TagSet  $tags
	 * @return void
	 */
	public function __construct(StoreInterface $store, TagSet $tags)
	{
		$this->tags = $tags;
		$this->store = $store;
	}

	/**
	 * Determine if an item exists in the cache.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function has($key)
	{
		return ! is_null($this->get($key));
	}

	/**
	 * Retrieve an item from the cache by key.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return mixed
	 */
	public function get($key, $default = null)
	{
		$value = $this->store->get($this->taggedItemKey($key));

		return ! is_null($value) ? $value : value($default);
	}

	/**
	 * Store an item in the cache for a given number of minutes.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @param  \DateTimeInterface|\DateInterval  $ttl
	 * @return void
	 */
	public function put($key, $value, $ttl)
	{
		$minutes = $this->getMinutes($ttl);

		if ( ! is_null($minutes))
		{
			$this->store->put($this->taggedItemKey($key), $value, $minutes);
		}
	}

	/**
	 * Store an item in the cache if the key does not exist.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @param  \DateTimeInterface|\DateInterval  $ttl
	 * @return bool
	 */
	public function add($key, $value, DateTimeInterface|DateInterval $ttl)
	{
		if (is_null($this->get($key)))
		{
			$this->put($key, $value, $ttl); return true;
		}

		return false;
	}

	/**
	 * Increment the value of an item in the cache.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function increment($key, $value = 1)
	{
		$this->store->increment($this->taggedItemKey($key), $value);
	}

	/**
	 * Increment the value of an item in the cache.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function decrement($key, $value = 1)
	{
		$this->store->decrement($this->taggedItemKey($key), $value);
	}

	/**
	 * Store an item in the cache indefinitely.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function forever($key, $value)
	{
		$this->store->forever($this->taggedItemKey($key), $value);
	}

	/**
	 * Remove an item from the cache.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function forget($key)
	{
		return $this->store->forget($this->taggedItemKey($key));
	}

	/**
	 * Remove all items from the cache.
	 *
	 * @return void
	 */
	public function flush()
	{
		$this->tags->reset();
	}

	/**
	 * Get an item from the cache, or store the default value.
	 *
	 * @param  string  $key
	 * @param  \DateTimeInterface|\DateInterval  $ttl
	 * @param  \Closure  $callback
	 * @return mixed
	 */
	public function remember($key, DateTimeInterface|DateInterval $ttl, Closure $callback)
	{
		// If the item exists in the cache we will just return this immediately
		// otherwise we will execute the given Closure and cache the result
		// of that execution for the given number of minutes in storage.
		if ( ! is_null($value = $this->get($key))) return $value;

		$this->put($key, $value = $callback(), $ttl);

		return $value;
	}

	/**
	 * Get an item from the cache, or store the default value forever.
	 *
	 * @param  string    $key
	 * @param  \Closure  $callback
	 * @return mixed
	 */
	public function sear($key, Closure $callback)
	{
		return $this->rememberForever($key, $callback);
	}

	/**
	 * Get an item from the cache, or store the default value forever.
	 *
	 * @param  string    $key
	 * @param  \Closure  $callback
	 * @return mixed
	 */
	public function rememberForever($key, Closure $callback)
	{
		// If the item exists in the cache we will just return this immediately
		// otherwise we will execute the given Closure and cache the result
		// of that execution for the given number of minutes. It's easy.
		if ( ! is_null($value = $this->get($key))) return $value;

		$this->forever($key, $value = $callback());

		return $value;
	}

	/**
	 * Get a fully qualified key for a tagged item.
	 *
	 * @param  string  $key
	 * @return string
	 */
	public function taggedItemKey($key)
	{
		return sha1($this->tags->getNamespace()).':'.$key;
	}

	/**
	 * Get the cache key prefix.
	 *
	 * @return string
	 */
	public function getPrefix()
	{
		return $this->store->getPrefix();
	}

	/**
	 * Calculate the number of minutes until the given TTL.
	 *
	 * @param  \DateTimeInterface|\DateInterval  $duration
	 * @return int|null
	 */
	protected function getMinutes($duration)
	{
		if ($duration instanceof DateInterval)
		{
			$duration = Carbon::now()->add($duration);
		}

		$fromNow = Carbon::instance($duration)->diffInMinutes();

		return $fromNow > 0 ? $fromNow : null;
	}

}
