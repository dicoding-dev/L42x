<?php namespace Illuminate\Foundation\Providers;

use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Handler\JsonResponseHandler;
use Illuminate\Foundation\Exceptions\Displayers\PlainDisplayer;
use Illuminate\Foundation\Exceptions\Displayers\WhoopsDisplayer;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\ServiceProvider;

class ExceptionServiceProvider extends ServiceProvider {

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->registerDisplayers();

		$this->registerHandler();
	}

	/**
	 * Register the exception displayers.
	 *
	 * @return void
	 */
	protected function registerDisplayers()
	{
		$this->registerPlainDisplayer();

		$this->registerDebugDisplayer();
	}

	/**
	 * Register the exception handler instance.
	 *
	 * @return void
	 */
	protected function registerHandler()
	{
		$this->app->singleton('exception', function($app)
		{
			return new Handler($app);
		});
	}

	/**
	 * Register the plain exception displayer.
	 *
	 * @return void
	 */
	protected function registerPlainDisplayer()
	{
		$this->app->singleton('exception.plain', function()
		{
			return new PlainDisplayer;
		});
	}

	/**
	 * Register the Whoops exception displayer.
	 *
	 * @return void
	 */
	protected function registerDebugDisplayer()
	{
		$this->registerWhoops();

		$this->app->singleton('exception.debug', function($app)
		{
			return new WhoopsDisplayer($app['whoops'], $app->runningInConsole());
		});
	}

	/**
	 * Register the Whoops error display service.
	 *
	 * @return void
	 */
	protected function registerWhoops()
	{
		$this->registerWhoopsHandler();

		$this->app->singleton('whoops', function($app)
		{
			// We will instruct Whoops to not exit after it displays the exception as it
			// will otherwise run out before we can do anything else. We just want to
			// let the framework go ahead and finish a request on this end instead.
			with($whoops = new Run)->allowQuit(false);

			$whoops->writeToOutput(false);

			return $whoops->pushHandler($app['whoops.handler']);
		});
	}

	/**
	 * Register the Whoops handler for the request.
	 *
	 * @return void
	 */
	protected function registerWhoopsHandler()
	{
		if ($this->shouldReturnJson())
		{
			$this->app->singleton('whoops.handler', function()
			{
				return new JsonResponseHandler;
			});
		}
		else
		{
			$this->registerPrettyWhoopsHandler();
		}
	}

	/**
	 * Determine if the error provider should return JSON.
	 *
	 * @return bool
	 */
	protected function shouldReturnJson()
	{
		return $this->app->runningInConsole() || $this->requestWantsJson();
	}

	/**
	 * Determine if the request warrants a JSON response.
	 *
	 * @return bool
	 */
	protected function requestWantsJson()
	{
		return $this->app['request']->ajax() || $this->app['request']->wantsJson();
	}

	/**
	 * Register the "pretty" Whoops handler.
	 *
	 * @return void
	 */
	protected function registerPrettyWhoopsHandler()
	{
		$this->app->singleton('whoops.handler', function()
		{
			with($handler = new PrettyPageHandler)->setEditor('sublime');

			return $handler;
		});
	}

}
