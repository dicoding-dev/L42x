<?php namespace Illuminate\Foundation\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

trait ApplicationTrait {

	/**
	 * The Illuminate application instance.
	 *
	 * @var \Illuminate\Foundation\Application
	 */
	protected $app;

	/**
	 * The HttpKernel client instance.
	 *
	 * @var \Illuminate\Foundation\Testing\Client
	 */
	protected $client;

	/**
	 * Refresh the application instance.
	 *
	 * @return void
	 */
	protected function refreshApplication()
	{
		$this->app = $this->createApplication();

		$this->client = $this->createClient();

		$this->app->setRequestForConsoleEnvironment();

		$this->app->boot();
	}

	/**
	 * Call the given URI and return the Response.
	 *
	 * @param  string  $method
	 * @param  string  $uri
	 * @param  array   $parameters
	 * @param  array   $cookies
	 * @param  array   $files
	 * @param  array   $server
	 * @param  string|null  $content
	 * @return \Illuminate\Testing\TestResponse
	 */
	public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
	{
		$files = array_merge($files, $this->extractFilesFromDataArray($parameters));

		$this->client->withRequestCookies($cookies)->request($method, $uri, $parameters, $files, $server, $content);

		return TestResponse::fromBaseResponse($this->client->getResponse(), $this->client->getRequest());
	}

	/**
	 * Visit the given URI with a GET request.
	 *
	 * @param  string  $uri
	 * @param  array   $headers
	 * @return \Illuminate\Testing\TestResponse
	 */
	public function get($uri, array $headers = [])
	{
		return $this->call('GET', $uri, [], [], [], $this->transformHeadersToServerVars($headers));
	}

	/**
	 * Visit the given URI with a POST request.
	 *
	 * @param  string  $uri
	 * @param  array   $data
	 * @param  array   $headers
	 * @return \Illuminate\Testing\TestResponse
	 */
	public function post($uri, array $data = [], array $headers = [])
	{
		return $this->call('POST', $uri, $data, [], [], $this->transformHeadersToServerVars($headers));
	}

	/**
	 * Visit the given URI with a PUT request.
	 *
	 * @param  string  $uri
	 * @param  array   $data
	 * @param  array   $headers
	 * @return \Illuminate\Testing\TestResponse
	 */
	public function put($uri, array $data = [], array $headers = [])
	{
		return $this->call('PUT', $uri, $data, [], [], $this->transformHeadersToServerVars($headers));
	}

	/**
	 * Visit the given URI with a PATCH request.
	 *
	 * @param  string  $uri
	 * @param  array   $data
	 * @param  array   $headers
	 * @return \Illuminate\Testing\TestResponse
	 */
	public function patch($uri, array $data = [], array $headers = [])
	{
		return $this->call('PATCH', $uri, $data, [], [], $this->transformHeadersToServerVars($headers));
	}

	/**
	 * Visit the given URI with a DELETE request.
	 *
	 * @param  string  $uri
	 * @param  array   $data
	 * @param  array   $headers
	 * @return \Illuminate\Testing\TestResponse
	 */
	public function delete($uri, array $data = [], array $headers = [])
	{
		return $this->call('DELETE', $uri, $data, [], [], $this->transformHeadersToServerVars($headers));
	}

	/**
	 * Transform headers array to array of $_SERVER vars with HTTP_* format.
	 *
	 * @param  array  $headers
	 * @return array
	 */
	protected function transformHeadersToServerVars(array $headers)
	{
		$server = [];

		foreach ($headers as $name => $value)
		{
			$name = strtr(strtoupper($name), '-', '_');

			if ( ! str_starts_with($name, 'HTTP_') && $name !== 'CONTENT_TYPE' && $name !== 'REMOTE_ADDR')
			{
				$name = 'HTTP_'.$name;
			}

			$server[$name] = $value;
		}

		return $server;
	}

	/**
	 * Extract the file uploads from the given data array.
	 *
	 * @param  array  $data
	 * @return array
	 */
	protected function extractFilesFromDataArray(&$data)
	{
		$files = [];

		foreach ($data as $key => $value)
		{
			if ($value instanceof SymfonyUploadedFile)
			{
				$files[$key] = $value;

				unset($data[$key]);
			}

			if (is_array($value))
			{
				$files[$key] = $this->extractFilesFromDataArray($value);

				$data[$key] = $value;
			}
		}

		return $files;
	}

	/**
	 * Set the session to the given array.
	 *
	 * @param  array  $data
	 * @return void
	 */
	public function session(array $data)
	{
		$this->startSession();

		foreach ($data as $key => $value)
		{
			$this->app['session']->put($key, $value);
		}
	}

	/**
	 * Flush all of the current session data.
	 *
	 * @return void
	 */
	public function flushSession()
	{
		$this->startSession();

		$this->app['session']->flush();
	}

	/**
	 * Start the session for the application.
	 *
	 * @return void
	 */
	protected function startSession()
	{
		if ( ! $this->app['session']->isStarted())
		{
			$this->app['session']->start();
		}
	}

	/**
	 * Set the currently logged in user for the application.
	 *
	 * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
	 * @param  string|null  $driver
	 * @return void
	 */
	public function be(Authenticatable $user, $driver = null)
	{
		$this->app['auth']->guard($driver)->setUser($user);
	}

	/**
	 * Seed a given database connection.
	 *
	 * @param  string  $class
	 * @return void
	 */
	public function seed($class = 'DatabaseSeeder')
	{
		$this->app['artisan']->call('db:seed', array('--class' => $class));
	}

	/**
	 * Create a new HttpKernel client instance.
	 *
	 * @param  array  $server
	 * @return \Symfony\Component\HttpKernel\Client
	 */
	protected function createClient(array $server = array())
	{
		return new Client($this->app, $server);
	}

}
