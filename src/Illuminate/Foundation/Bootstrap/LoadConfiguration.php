<?php namespace Illuminate\Foundation\Bootstrap;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * ponytail: v13's LoadConfiguration bootstrapper, without the config cache and the
 * framework's default config, which come with the flip. The start script runs it.
 * Remove at the flip.
 */
class LoadConfiguration {

	/**
	 * Bootstrap the given application.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	public function bootstrap(Application $app)
	{
		$app->instance('config', $config = new Repository);

		foreach ($this->getConfigurationFiles($app) as $name => $path)
		{
			$config->set($name, (fn () => require $path)());
		}

		date_default_timezone_set($config->get('app.timezone', 'UTC'));

		mb_internal_encoding('UTF-8');
	}

	/**
	 * Get all of the configuration files for the application.
	 *
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return array<string, string>
	 */
	protected function getConfigurationFiles(Application $app)
	{
		$files = array();

		$configPath = realpath($app->configPath());

		if ( ! $configPath) return array();

		foreach (Finder::create()->files()->name('*.php')->in($configPath) as $file)
		{
			$directory = $this->getNestedDirectory($file, $configPath);

			$files[$directory.basename($file->getRealPath(), '.php')] = $file->getRealPath();
		}

		ksort($files, SORT_NATURAL);

		return $files;
	}

	/**
	 * Get the configuration file nesting path.
	 *
	 * @param  \SplFileInfo  $file
	 * @param  string  $configPath
	 * @return string
	 */
	protected function getNestedDirectory(SplFileInfo $file, $configPath)
	{
		$directory = $file->getPath();

		if ($nested = trim(str_replace($configPath, '', $directory), DIRECTORY_SEPARATOR))
		{
			$nested = str_replace(DIRECTORY_SEPARATOR, '.', $nested).'.';
		}

		return $nested;
	}

}
