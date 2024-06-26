<?php namespace Illuminate\Queue\Console;

use Illuminate\Console\Command;

class ListFailedCommand extends Command {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'queue:failed';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'List all of the failed queue jobs';

	/**
	 * Execute the console command.
	 *
	 * @return int
     */
	public function fire()
	{
		$rows = array();

		foreach ($this->laravel['queue.failer']->all() as $failed)
		{
			$rows[] = $this->parseFailedJob((array) $failed);
		}

		if (count($rows) == 0)
		{
			$this->info('No failed jobs!');
            return 0;
		}

		$table = $this->getHelperSet()->get('table');

		$table->setHeaders(array('ID', 'Connection', 'Queue', 'Class', 'Failed At'))
              ->setRows($rows)
              ->render($this->output);

        return 0;
	}

	/**
	 * Parse the failed job row.
	 *
	 * @param  array  $failed
	 * @return array
	 */
	protected function parseFailedJob(array $failed)
	{
		$row = array_values(array_except($failed, array('payload')));

		array_splice($row, 3, 0, array_get(json_decode((string) $failed['payload'], true), 'job'));

		return $row;
	}

}
