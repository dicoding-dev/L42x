<?php namespace Illuminate\Session;

use Illuminate\Cookie\CookieJar;
use Symfony\Component\HttpFoundation\Request;

class CookieSessionHandler implements \SessionHandlerInterface {

	/**
	 * The cookie jar instance.
	 *
	 * @var \Illuminate\Cookie\CookieJar
	 */
	protected $cookie;

	/**
	 * The request instance.
	 *
	 * @var \Symfony\Component\HttpFoundation\Request
	 */
	protected $request;

	/**
	 * Create a new cookie driven handler instance.
	 *
	 * @param  \Illuminate\Cookie\CookieJar  $cookie
	 * @param  int  $minutes
	 * @return void
	 */
	public function __construct(CookieJar $cookie, $minutes)
	{
		$this->cookie = $cookie;
		$this->minutes = $minutes;
	}

	/**
	 * {@inheritDoc}
	 */
	public function open($savePath, $sessionName): bool
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
	public function read($sessionId): string|false
	{
		return $this->request->cookies->get($sessionId) ?: '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function write($sessionId, $data): bool
	{
		$this->cookie->queue($sessionId, $data, $this->minutes);
        return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function destroy($sessionId): bool
	{
		$this->cookie->queue($this->cookie->forget($sessionId));

        return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function gc($lifetime): int|false
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
