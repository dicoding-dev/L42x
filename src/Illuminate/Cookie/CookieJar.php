<?php namespace Illuminate\Cookie;

use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Cookie;

class CookieJar {

	/**
	 * The default path (if specified).
	 *
	 * @var string
	 */
	protected $path = '/';

	/**
	 * The default domain (if specified).
	 *
	 * @var string
	 */
	protected $domain = null;

	/**
	 * The default secure setting.
	 *
	 * @var bool
	 */
	protected $secure = false;

	/**
	 * The default SameSite setting (fork preserves Symfony's 'lax' default;
	 * L13 defaults to null + config-driven middleware, introduced at the flip).
	 *
	 * @var string|null
	 */
	protected $sameSite = 'lax';

	/**
	 * All of the cookies queued for sending.
	 *
	 * @var array
	 */
	protected $queued = array();

	/**
	 * Create a new cookie instance.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  int     $minutes
	 * @param  string  $path
	 * @param  string  $domain
	 * @param  bool|null  $secure
	 * @param  bool    $httpOnly
	 * @param  bool    $raw
	 * @param  string|null  $sameSite
	 * @return \Symfony\Component\HttpFoundation\Cookie
	 */
	public function make($name, $value, $minutes = 0, $path = null, $domain = null, $secure = null, $httpOnly = true, $raw = false, $sameSite = null)
	{
		list($path, $domain, $secure, $sameSite) = $this->getPathAndDomain($path, $domain, $secure, $sameSite);

		$time = ($minutes == 0) ? 0 : time() + ($minutes * 60);

		return new Cookie($name, $value, $time, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
	}

	/**
	 * Create a cookie that lasts "forever" (five years).
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  string  $path
	 * @param  string  $domain
	 * @param  bool|null  $secure
	 * @param  bool    $httpOnly
	 * @param  bool    $raw
	 * @param  string|null  $sameSite
	 * @return \Symfony\Component\HttpFoundation\Cookie
	 */
	public function forever($name, $value, $path = null, $domain = null, $secure = null, $httpOnly = true, $raw = false, $sameSite = null)
	{
		return $this->make($name, $value, 2628000, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
	}

	/**
	 * Expire the given cookie.
	 *
	 * @param  string  $name
	 * @param  string  $path
	 * @param  string  $domain
	 * @return \Symfony\Component\HttpFoundation\Cookie
	 */
	public function forget($name, $path = null, $domain = null)
	{
		return $this->make($name, null, -2628000, $path, $domain);
	}

	/**
	 * Determine if a cookie has been queued.
	 *
	 * @param  string  $key
	 * @param  string|null  $path
	 * @return bool
	 */
	public function hasQueued($key, $path = null)
	{
		return ! is_null($this->queued($key, null, $path));
	}

	/**
	 * Get a queued cookie instance.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @param  string|null  $path
	 * @return \Symfony\Component\HttpFoundation\Cookie
	 */
	public function queued($key, $default = null, $path = null)
	{
		$queued = isset($this->queued[$key]) ? $this->queued[$key] : null;

		if (is_null($queued))
		{
			return value($default);
		}

		if (is_null($path))
		{
			return Arr::last($queued, null, $default);
		}

		return isset($queued[$path]) ? $queued[$path] : value($default);
	}

	/**
	 * Queue a cookie to send with the next response.
	 *
	 * @param  mixed  ...$parameters
	 * @return void
	 */
	public function queue(...$parameters)
	{
		if (isset($parameters[0]) && $parameters[0] instanceof Cookie)
		{
			$cookie = $parameters[0];
		}
		else
		{
			$cookie = $this->make(...array_values($parameters));
		}

		if ( ! isset($this->queued[$cookie->getName()]))
		{
			$this->queued[$cookie->getName()] = array();
		}

		$this->queued[$cookie->getName()][$cookie->getPath()] = $cookie;
	}

	/**
	 * Queue a cookie to expire with the next response.
	 *
	 * @param  string  $name
	 * @param  string|null  $path
	 * @param  string|null  $domain
	 * @return void
	 */
	public function expire($name, $path = null, $domain = null)
	{
		$this->queue($this->forget($name, $path, $domain));
	}

	/**
	 * Remove a cookie from the queue.
	 *
	 * @param  string  $name
	 * @param  string|null  $path
	 * @return void
	 */
	public function unqueue($name, $path = null)
	{
		if (is_null($path))
		{
			unset($this->queued[$name]);

			return;
		}

		unset($this->queued[$name][$path]);

		if (empty($this->queued[$name]))
		{
			unset($this->queued[$name]);
		}
	}

	/**
	 * Get the path and domain, or the default values.
	 *
	 * @param  string  $path
	 * @param  string  $domain
	 * @param  bool|null  $secure
	 * @param  string|null  $sameSite
	 * @return array
	 */
	protected function getPathAndDomain($path, $domain, $secure = null, $sameSite = null)
	{
		return array($path ?: $this->path, $domain ?: $this->domain, is_bool($secure) ? $secure : $this->secure, $sameSite ?: $this->sameSite);
	}

	/**
	 * Set the default path and domain for the jar.
	 *
	 * @param  string  $path
	 * @param  string  $domain
	 * @return $this
	 */
	public function setDefaultPathAndDomain($path, $domain)
	{
		list($this->path, $this->domain) = array($path, $domain);

		return $this;
	}

	/**
	 * Get the cookies which have been queued for the next request
	 *
	 * @return \Symfony\Component\HttpFoundation\Cookie[]
	 */
	public function getQueuedCookies()
	{
		return Arr::flatten($this->queued);
	}

	/**
	 * Flush the cookies which have been queued for the next request.
	 *
	 * @return $this
	 */
	public function flushQueuedCookies()
	{
		$this->queued = array();

		return $this;
	}

}
