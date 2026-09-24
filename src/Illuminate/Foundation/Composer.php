<?php namespace Illuminate\Foundation;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * ponytail: extends v13's Support\Composer so `$app['composer']` satisfies the
 * Illuminate\Support\Composer type hint of v13's MigrateMakeCommand (make:migration),
 * which otherwise fails to construct and is skipped at artisan boot. The fork keeps its
 * own Process-returning dumpAutoloads()/dumpOptimized() used by `artisan optimize`.
 * Remove at the console swap (task 4.2).
 */
class Composer extends \Illuminate\Support\Composer {

	/**
	 * The filesystem instance.
	 *
	 * @var \Illuminate\Filesystem\Filesystem
	 */
	protected $files;

	/**
	 * The working path to regenerate from.
	 *
	 * @var string
	 */
	protected $workingPath;

	/**
	 * Create a new Composer manager instance.
	 *
	 * @param  \Illuminate\Filesystem\Filesystem  $files
	 * @param  string  $workingPath
	 * @return void
	 */
	public function __construct(Filesystem $files, $workingPath = null)
	{
		$this->files = $files;
		$this->workingPath = $workingPath;
	}

    /**
     * Regenerate the Composer autoloader files.
     *
     * @param string $extra
     *
     * @return Process
     */
	public function dumpAutoloads($extra = '', $composerBinary = null): Process
    {
        $command = trim($this->findComposer().' dump-autoload '.$extra);

		$process = $this->getProcess(explode(' ', $command));

		$process->run();

        return $process;
	}

	/**
	 * Regenerate the optimized Composer autoloader files.
	 *
	 * @return Process
	 */
	public function dumpOptimized($composerBinary = null): Process
    {
		return $this->dumpAutoloads('--optimize');
	}

	/**
	 * Get the composer command for the environment.
	 *
	 * @return string
	 */
	public function findComposer($composerBinary = null)
	{
		if ($this->files->exists($this->workingPath.'/composer.phar'))
		{
			return '"'.PHP_BINARY.'" composer.phar';
		}

		return 'composer';
	}

	/**
	 * Get a new Symfony process instance.
	 * @param string[] $commands
	 * @return \Symfony\Component\Process\Process
	 */
	protected function getProcess(array $command, array $env = []): Process
    {
		return (new Process($command, $this->workingPath))->setTimeout(null);
	}

	/**
	 * Set the working path used by the class.
	 *
	 * @param  string  $path
	 * @return $this
	 */
	public function setWorkingPath($path)
	{
		$this->workingPath = realpath($path);

		return $this;
	}

}
