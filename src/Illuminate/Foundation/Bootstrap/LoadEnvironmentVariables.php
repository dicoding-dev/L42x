<?php namespace Illuminate\Foundation\Bootstrap;

use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * ponytail: v13's LoadEnvironmentVariables bootstrapper, without the config cache check
 * and the invalid-file message. The start script runs it before the L4.2 .env.{env}.php
 * file, which still wins on the keys both define. Remove at the flip.
 */
class LoadEnvironmentVariables {

	/**
	 * Bootstrap the given application.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	public function bootstrap(Application $app)
	{
		$this->checkForSpecificEnvironmentFile($app);

		$this->createDotenv($app)->safeLoad();
	}

	/**
	 * Detect if a custom environment file matching the APP_ENV exists.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	protected function checkForSpecificEnvironmentFile($app)
	{
		if ($app->runningInConsole() &&
			($input = new ArgvInput)->hasParameterOption('--env') &&
			$this->setEnvironmentFilePath($app, $app->environmentFile().'.'.$input->getParameterOption('--env')))
		{
			return;
		}

		$environment = Env::get('APP_ENV');

		if ( ! $environment) return;

		$this->setEnvironmentFilePath($app, $app->environmentFile().'.'.$environment);
	}

	/**
	 * Load a custom environment file.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @param  string  $file
	 * @return bool
	 */
	protected function setEnvironmentFilePath($app, $file)
	{
		if (is_file($app->environmentPath().'/'.$file))
		{
			$app->loadEnvironmentFrom($file);

			return true;
		}

		return false;
	}

	/**
	 * Create a Dotenv instance.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return \Dotenv\Dotenv
	 */
	protected function createDotenv($app)
	{
		return Dotenv::create(Env::getRepository(), $app->environmentPath(), $app->environmentFile());
	}

}
