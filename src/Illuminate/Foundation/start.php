<?php

/*
|--------------------------------------------------------------------------
| Set PHP Error Reporting Options
|--------------------------------------------------------------------------
|
| Here we will set the strictest error reporting options, and also turn
| off PHP's error reporting, since all errors will be handled by the
| framework and we don't want any output leaking back to the user.
|
*/

error_reporting(-1);

/*
|--------------------------------------------------------------------------
| Check Extensions
|--------------------------------------------------------------------------
|
| Laravel requires a few extensions to function. Here we will check the
| loaded extensions to make sure they are present. If not we'll just
| bail from here. Otherwise, Composer will crazily fall back code.
|
*/

//if ( ! extension_loaded('mcrypt'))
//{
//	echo 'Mcrypt PHP extension required.'.PHP_EOL;
//
//	exit(1);
//}

/*
|--------------------------------------------------------------------------
| Register Class Imports
|--------------------------------------------------------------------------
|
| Here we will just import a few classes that we need during the booting
| of the framework. These are mainly classes that involve loading the
| config files for this application, such as the config repository.
|
*/

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Env;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Config\EnvironmentVariables;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

/*
|--------------------------------------------------------------------------
| Bind The Application In The Container
|--------------------------------------------------------------------------
|
| This may look strange, but we actually want to bind the app into itself
| in case we need to Facade test an application. This will allow us to
| resolve the "app" key out of this container for this app's facade.
|
*/

$app->instance('app', $app);

/*
|--------------------------------------------------------------------------
| Load The Illuminate Facades
|--------------------------------------------------------------------------
|
| The facades provide a terser static interface over the various parts
| of the application, allowing their methods to be accessed through
| a mixtures of magic methods and facade derivatives. It's slick.
|
*/

Facade::clearResolvedInstances();

Facade::setFacadeApplication($app);

/*
|--------------------------------------------------------------------------
| Register Facade Aliases To Full Classes
|--------------------------------------------------------------------------
|
| By default, we use short keys in the container for each of the core
| pieces of the framework. Here we will register the aliases for a
| list of all of the fully qualified class names making DI easy.
|
*/

$app->registerCoreContainerAliases();

/*
|--------------------------------------------------------------------------
| Register The Environment Variables
|--------------------------------------------------------------------------
|
| v13's LoadEnvironmentVariables loads the .env file first. Unless the
| application has set its environment, APP_ENV then names it, defaulting to
| production as v13's config does. The L4.2 .env.{env}.php file loads after
| that, so its values win on the keys both files define.
|
*/

$app->make(LoadEnvironmentVariables::class)->bootstrap($app);

if ( ! $app->bound('env')) $app->detectEnvironment(fn() => Env::get('APP_ENV', 'production'));

$env = $app['env'];

with($envVariables = new EnvironmentVariables(
	$app->getEnvironmentVariablesLoader()))->load($env);

/*
|--------------------------------------------------------------------------
| Register The Configuration Repository
|--------------------------------------------------------------------------
|
| v13's LoadConfiguration loads every file in the configuration directory,
| with no environment cascade. Callbacks registered with afterBootstrapping
| run next, before any service provider registers.
|
*/

$app->make(LoadConfiguration::class)->bootstrap($app);

$app['events']->dispatch('bootstrapped: '.LoadConfiguration::class, array($app));

/*
|--------------------------------------------------------------------------
| Register Application Exception Handling
|--------------------------------------------------------------------------
|
| We will go ahead and register the application exception handling here
| which will provide a great output of exception details and a stack
| trace in the case of exceptions while an application is running.
|
*/

$app->startExceptionHandling();

if ($env != 'testing') ini_set('display_errors', 'Off');

$config = $app['config']['app'];

/*
|--------------------------------------------------------------------------
| Register The Alias Loader
|--------------------------------------------------------------------------
|
| The alias loader is responsible for lazy loading the class aliases setup
| for the application. We will only register it if the "config" service
| is bound in the application since it contains the alias definitions.
|
*/

$aliases = $config['aliases'];

AliasLoader::getInstance($aliases)->register();

/*
|--------------------------------------------------------------------------
| Enable HTTP Method Override
|--------------------------------------------------------------------------
|
| Next we will tell the request class to allow HTTP method overriding
| since we use this to simulate PUT and DELETE requests from forms
| as they are not currently supported by plain HTML form setups.
|
*/

Request::enableHttpMethodParameterOverride();

/*
|--------------------------------------------------------------------------
| Register The Core Service Providers
|--------------------------------------------------------------------------
|
| The Illuminate core service providers register all of the core pieces
| of the Illuminate framework including session, caching, encryption
| and more. It's simply a convenient wrapper for the registration.
|
*/

$providers = $config['providers'];

$app->getProviderRepository()->load($app, $providers);

/*
|--------------------------------------------------------------------------
| Register Booted Start Files
|--------------------------------------------------------------------------
|
| Once the application has been booted there are several "start" files
| we will want to include. We'll register our "booted" handler here
| so the files are included after the application gets booted up.
|
*/

$app->booted(function() use ($app, $env)
{

	/*
	|--------------------------------------------------------------------------
	| Load The Application Start Script
	|--------------------------------------------------------------------------
	|
	| The start scripts gives this application the opportunity to override
	| any of the existing IoC bindings, as well as register its own new
	| bindings for things like repositories, etc. We'll load it here.
	|
	*/

	$path = $app['path'].'/start/global.php';

	if (file_exists($path)) require $path;

	/*
	|--------------------------------------------------------------------------
	| Load The Environment Start Script
	|--------------------------------------------------------------------------
	|
	| The environment start script is only loaded if it exists for the app
	| environment currently active, which allows some actions to happen
	| in one environment while not in the other, keeping things clean.
	|
	*/

	$path = $app['path']."/start/{$env}.php";

	if (file_exists($path)) require $path;

	/*
	|--------------------------------------------------------------------------
	| Load The Application Routes
	|--------------------------------------------------------------------------
	|
	| The Application routes are kept separate from the application starting
	| just to keep the file a little cleaner. We'll go ahead and load in
	| all of the routes now and return the application to the callers.
	|
	*/

	$routes = $app['path'].'/routes.php';

	if (file_exists($routes)) require $routes;

});
