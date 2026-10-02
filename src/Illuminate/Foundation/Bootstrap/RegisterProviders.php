<?php namespace Illuminate\Foundation\Bootstrap;

use Illuminate\Foundation\Application;

/**
 * ponytail: v13's RegisterProviders bootstrapper, without the config cache check and the
 * framework's default providers, which come with the flip. The start script runs it.
 * Remove at the flip.
 */
class RegisterProviders {

	/**
	 * The service providers that should be merged before registration.
	 *
	 * @var array
	 */
	protected static $merge = array();

	/**
	 * The path to the bootstrap provider configuration file.
	 *
	 * @var string|null
	 */
	protected static $bootstrapProviderPath;

	/**
	 * Bootstrap the given application.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	public function bootstrap(Application $app)
	{
		$this->mergeAdditionalProviders($app);

		$app->registerConfiguredProviders();
	}

	/**
	 * Merge the additional configured providers into the configuration.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	protected function mergeAdditionalProviders(Application $app)
	{
		if (static::$bootstrapProviderPath && file_exists(static::$bootstrapProviderPath))
		{
			$packageProviders = require static::$bootstrapProviderPath;

			foreach ($packageProviders as $index => $provider)
			{
				if ( ! class_exists($provider))
				{
					unset($packageProviders[$index]);
				}
			}
		}

		$app->make('config')->set('app.providers', array_merge(
			$app->make('config')->get('app.providers', array()),
			static::$merge,
			array_values($packageProviders ?? array())
		));
	}

	/**
	 * Merge the given providers into the provider configuration before registration.
	 *
	 * @param  array  $providers
	 * @param  string|null  $bootstrapProviderPath
	 * @return void
	 */
	public static function merge(array $providers, ?string $bootstrapProviderPath = null)
	{
		static::$bootstrapProviderPath = $bootstrapProviderPath;

		static::$merge = array_values(array_filter(array_unique(
			array_merge(static::$merge, $providers)
		)));
	}

	/**
	 * Flush the bootstrapper's global state.
	 *
	 * @return void
	 */
	public static function flushState()
	{
		static::$bootstrapProviderPath = null;

		static::$merge = array();
	}

}
