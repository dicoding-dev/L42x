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
use Illuminate\Support\Env;
use Illuminate\Config\EnvironmentVariables;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;

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

/*
|--------------------------------------------------------------------------
| Register The Facades
|--------------------------------------------------------------------------
|
| v13's RegisterFacades points the facades at this application and
| registers the class aliases from the app.aliases configuration.
|
*/

$app->make(RegisterFacades::class)->bootstrap($app);

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
| Register The Service Providers
|--------------------------------------------------------------------------
|
| v13's RegisterProviders registers the app.providers configuration, then
| the providers given to withProviders() and those in bootstrap/providers.php.
|
*/

$app->make(RegisterProviders::class)->bootstrap($app);

/*
|--------------------------------------------------------------------------
| Load The Application Routes
|--------------------------------------------------------------------------
|
| Once the application has booted, the routes load from app/routes.php.
| v13 has no app/start files, so the start script no longer loads them.
|
*/

$app->booted(function() use ($app)
{
	$routes = $app['path'].'/routes.php';

	if (file_exists($routes)) require $routes;
});
