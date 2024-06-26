<?php namespace Illuminate\Foundation\Console;

use Illuminate\Console\Command;

class UpCommand extends Command {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'up';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = "Bring the application out of maintenance mode";

	/**
	 * Execute the console command.
	 *
	 * @return int
     */
	public function fire()
	{
		@unlink($this->laravel['config']['app.manifest'].'/down');

		$this->info('Application is now live.');

        return 0;
	}

}
