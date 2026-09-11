<?php namespace Illuminate\Session;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use SessionHandlerInterface;
use stdClass;

class Store implements Session {

	/**
	 * The session ID.
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * The session name.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * The session attributes.
	 *
	 * @var array
	 */
	protected $attributes = array();

	/**
	 * The session handler implementation.
	 *
	 * @var \SessionHandlerInterface
	 */
	protected $handler;

	/**
	 * Session store started status.
	 *
	 * @var bool
	 */
	protected $started = false;

	/**
	 * Create a new session instance.
	 *
	 * @param  string  $name
	 * @param  \SessionHandlerInterface  $handler
	 * @param  string|null  $id
	 * @return void
	 */
	public function __construct($name, SessionHandlerInterface $handler, $id = null)
	{
		$this->setId($id);
		$this->name = $name;
		$this->handler = $handler;
	}

	/**
	 * Start the session, reading the data from a handler.
	 *
	 * @return bool
	 */
	public function start(): bool
	{
		$this->loadSession();

		if ( ! $this->has('_token')) $this->regenerateToken();

		return $this->started = true;
	}

	/**
	 * Load the session data from the handler.
	 *
	 * @return void
	 */
	protected function loadSession()
	{
		$this->attributes = $this->readFromHandler();
	}

	/**
	 * Read the session data from the handler.
	 *
	 * @return array
	 */
	protected function readFromHandler()
	{
		$data = $this->handler->read($this->getId());

		return $data ? unserialize($data) : array();
	}

	/**
	 * Get the current session ID.
	 *
	 * @return string
	 */
	public function getId(): string
    {
		return $this->id;
	}

	/**
	 * Set the session ID.
	 *
	 * @param  string  $id
	 * @return void
	 */
	public function setId($id)
	{
		if ( ! $this->isValidId($id))
		{
			$id = $this->generateSessionId();
		}

		$this->id = $id;
	}

	/**
	 * Determine if this is a valid session ID.
	 *
	 * @param  string  $id
	 * @return bool
	 */
	public function isValidId($id): bool
    {
        return \is_string($id) && ctype_alnum($id) && \strlen($id) === 40;
	}

	/**
	 * Get a new, random session ID.
	 *
	 * @return string
	 */
	protected function generateSessionId(): string
    {
        return Str::random(40);
	}

	/**
	 * Get the name of the session.
	 *
	 * @return string
	 */
	public function getName(): string
    {
		return $this->name;
	}

	/**
	 * Set the name of the session.
	 *
	 * @param  string  $name
	 * @return void
	 */
	public function setName($name)
	{
		$this->name = $name;
	}

	/**
	 * Flush the session data and regenerate the ID.
	 *
	 * @return bool
	 */
	public function invalidate(): bool
    {
		$this->attributes = array();

		$this->migrate();

		return true;
	}

	/**
	 * Generate a new session ID for the session.
	 *
	 * @param  bool  $destroy
	 * @return bool
	 */
	public function migrate($destroy = false): bool
    {
		if ($destroy) $this->handler->destroy($this->getId());

		$this->setExists(false);

		$this->id = $this->generateSessionId(); return true;
	}

	/**
	 * Generate a new session identifier.
	 *
	 * @param  bool  $destroy
	 * @return bool
	 */
	public function regenerate($destroy = false)
	{
		return $this->migrate($destroy);
	}

	/**
	 * Save the session data to storage.
	 *
	 * @return void
	 */
	public function save()
	{
		$this->ageFlashData();

		$this->handler->write($this->getId(), serialize($this->attributes));

		$this->started = false;
	}

	/**
	 * Age the flash data for the session.
	 *
	 * @return void
	 */
	public function ageFlashData()
	{
		foreach ($this->get('flash.old', array()) as $old) { $this->forget($old); }

		$this->put('flash.old', $this->get('flash.new', array()));

		$this->put('flash.new', array());
	}

	/**
	 * Checks if a key exists.
	 *
	 * @param  string|array  $key
	 * @return bool
	 */
	public function exists($key): bool
    {
		$placeholder = new stdClass;

		foreach (is_array($key) ? $key : func_get_args() as $k)
		{
			if ($this->get($k, $placeholder) === $placeholder) return false;
		}

		return true;
	}

	/**
	 * Determine if a key is present and not null.
	 *
	 * @param  string  $name
	 * @return bool
	 */
	public function has($name): bool
    {
		return ! is_null($this->get($name));
	}

	/**
	 * Get an item from the session.
	 *
	 * @param  string  $name
	 * @param  mixed  $default
	 * @return mixed
	 */
	public function get($name, $default = null): mixed
    {
		return array_get($this->attributes, $name, $default);
	}

	/**
	 * Get the value of a given key and then forget it.
	 *
	 * @param  string  $key
	 * @param  string  $default
	 * @return mixed
	 */
	public function pull($key, $default = null)
	{
		return array_pull($this->attributes, $key, $default);
	}

	/**
	 * Determine if the session contains old input.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function hasOldInput($key = null)
	{
		$old = $this->getOldInput($key);

		return is_null($key) ? count($old) > 0 : ! is_null($old);
	}

	/**
	 * Get the requested item from the flashed input array.
	 *
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return mixed
	 */
	public function getOldInput($key = null, $default = null)
	{
		$input = $this->get('_old_input', array());

		// Input that is flashed to the session can be easily retrieved by the
		// developer, making repopulating old forms and the like much more
		// convenient, since the request's previous input is available.
		return array_get($input, $key, $default);
	}

	/**
	 * Set a key / value pair in the session.
	 *
	 * @param  string  $name
	 * @param  mixed   $value
	 * @return void
	 */
	public function set($name, $value)
	{
		array_set($this->attributes, $name, $value);
	}

	/**
	 * Put a key / value pair or array of key / value pairs in the session.
	 *
	 * @param  string|array  $key
	 * @param  mixed|null  	 $value
	 * @return void
	 */
	public function put($key, $value = null)
	{
		if ( ! is_array($key)) $key = array($key => $value);

		foreach ($key as $arrayKey => $arrayValue)
		{
			$this->set($arrayKey, $arrayValue);
		}
	}

	/**
	 * Push a value onto a session array.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function push($key, $value)
	{
		$array = $this->get($key, array());

		$array[] = $value;

		$this->put($key, $array);
	}

	/**
	 * Flash a key / value pair to the session.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	// ponytail: keeps 4.2 flash.{new,old} keys, not v13 _flash.* — align at the illuminate/session swap when live sessions drain.
	public function flash($key, $value = true)
	{
		$this->put($key, $value);

		$this->push('flash.new', $key);

		$this->removeFromOldFlashData(array($key));
	}

	/**
	 * Flash an input array to the session.
	 *
	 * @param  array  $value
	 * @return void
	 */
	public function flashInput(array $value)
	{
		$this->flash('_old_input', $value);
	}

	/**
	 * Reflash all of the session flash data.
	 *
	 * @return void
	 */
	public function reflash()
	{
		$this->mergeNewFlashes($this->get('flash.old', array()));

		$this->put('flash.old', array());
	}

	/**
	 * Reflash a subset of the current flash data.
	 *
	 * @param  array|mixed  $keys
	 * @return void
	 */
	public function keep($keys = null)
	{
		$keys = is_array($keys) ? $keys : func_get_args();

		$this->mergeNewFlashes($keys);

		$this->removeFromOldFlashData($keys);
	}

	/**
	 * Merge new flash keys into the new flash array.
	 *
	 * @param  array  $keys
	 * @return void
	 */
	protected function mergeNewFlashes(array $keys)
	{
		$values = array_unique(array_merge($this->get('flash.new', array()), $keys));

		$this->put('flash.new', $values);
	}

	/**
	 * Remove the given keys from the old flash data.
	 *
	 * @param  array  $keys
	 * @return void
	 */
	protected function removeFromOldFlashData(array $keys)
	{
		$this->put('flash.old', array_diff($this->get('flash.old', array()), $keys));
	}

	/**
	 * Get all of the session data.
	 *
	 * @return array
	 */
	public function all(): array
    {
		return $this->attributes;
	}

	/**
	 * Replace the given session attributes entirely.
	 *
	 * @param  array  $attributes
	 * @return void
	 */
	public function replace(array $attributes)
	{
		foreach ($attributes as $key => $value)
		{
			$this->put($key, $value);
		}
	}

	/**
	 * Remove an item from the session, returning its value.
	 *
	 * @param  string  $name
	 * @return mixed
	 */
	public function remove($name): mixed
    {
		return array_pull($this->attributes, $name);
	}

	/**
	 * Remove one or many items from the session.
	 *
	 * @param  string|array  $key
	 * @return void
	 */
	public function forget($key)
	{
		array_forget($this->attributes, $key);
	}

	/**
	 * Remove all of the items from the session.
	 *
	 * @return void
	 */
	public function flush()
	{
		$this->attributes = array();
	}

	/**
	 * Determine if the session has been started.
	 *
	 * @return bool
	 */
	public function isStarted(): bool
    {
		return $this->started;
	}

	/**
	 * Get the CSRF token value.
	 *
	 * @return string
	 */
	public function token()
	{
		return $this->get('_token');
	}

	/**
	 * Get the CSRF token value.
	 *
	 * @return string
	 */
	public function getToken()
	{
		return $this->token();
	}

	/**
	 * Regenerate the CSRF token value.
	 *
	 * @return void
	 */
	public function regenerateToken()
	{
		$this->put('_token', Str::random(40));
	}

    /**
     * Get the previous URL from the session.
     *
     * @return string|null
     */
    public function previousUrl()
    {
        return $this->get('_previous.url');
    }

    /**
     * Set the "previous" URL in the session.
     *
     * @param  string  $url
     * @return void
     */
    public function setPreviousUrl($url)
    {
        $this->put('_previous.url', $url);
    }

	/**
	 * Set the existence of the session on the handler if applicable.
	 *
	 * @param  bool  $value
	 * @return void
	 */
	public function setExists($value)
	{
		if ($this->handler instanceof ExistenceAwareInterface)
		{
			$this->handler->setExists($value);
		}
	}

	/**
	 * Get the underlying session handler implementation.
	 *
	 * @return \SessionHandlerInterface
	 */
	public function getHandler()
	{
		return $this->handler;
	}

	/**
	 * Determine if the session handler needs a request.
	 *
	 * @return bool
	 */
	public function handlerNeedsRequest()
	{
		return $this->handler instanceof CookieSessionHandler;
	}

	/**
	 * Set the request on the handler instance.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return void
	 */
	public function setRequestOnHandler($request)
	{
		if ($this->handlerNeedsRequest())
		{
			$this->handler->setRequest($request);
		}
	}

}
