<?php namespace Illuminate\Foundation\Bootstrap;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

/**
 * ponytail: v13's RegisterFacades bootstrapper, without the package manifest's aliases,
 * which come with the flip. The start script runs it. Remove at the flip.
 */
class RegisterFacades {

	/**
	 * Bootstrap the given application.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	public function bootstrap(Application $app)
	{
		Facade::clearResolvedInstances();

		Facade::setFacadeApplication($app);

		AliasLoader::getInstance($app->make('config')->get('app.aliases', array()))->register();
	}

}
