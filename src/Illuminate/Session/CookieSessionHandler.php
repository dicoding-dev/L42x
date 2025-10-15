<?php namespace Illuminate\Session;

use Illuminate\Cookie\CookieJar;
use Symfony\Component\HttpFoundation\Request;

class CookieSessionHandler implements \SessionHandlerInterface {

    /**
     * The cookie lifetime in minutes.
     */
	protected int $minutes;

    /**
	 * The cookie jar instance.
	 */
	protected CookieJar $cookie;

	/**
	 * The request instance.
	 */
	protected Request $request;

	/**
	 * Create a new cookie driven handler instance.
	 *
	 * @param  \Illuminate\Cookie\CookieJar  $cookie
	 * @param  int  $minutes
	 * @return void
	 */
	public function __construct(CookieJar $cookie, int $minutes)
	{
		$this->cookie = $cookie;
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
	public function read($id): string|false
	{
		return $this->request->cookies->get($id) ?: '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function write(string $id, string $data): bool
	{
		$this->cookie->queue($id, $data, $this->minutes);
        return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function destroy(string $id): bool
	{
		try {
            $this->cookie->queue($this->cookie->forget($id));
        } catch (\Throwable) {
            return false;
        }

        return 1;
	}

	/**
	 * {@inheritDoc}
	 */
	public function gc(int $max_lifetime): int|false
    {
		return true;
	}

	/**
	 * Set the request instance.
	 *
	 * @param  \Symfony\Component\HttpFoundation\Request  $request
	 * @return void
	 */
	public function setRequest(Request $request)
	{
		$this->request = $request;
	}

}
