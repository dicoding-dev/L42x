<?php namespace Illuminate\Session;

use Symfony\Component\Finder\Finder;
use Illuminate\Filesystem\Filesystem;

class FileSessionHandler implements \SessionHandlerInterface {

	/**
	 * The filesystem instance.
	 */
	protected Filesystem $files;

	/**
	 * The path where sessions should be stored.
	 */
	protected string $path;

	/**
	 * Create a new file driven handler instance.
	 *
	 * @param  \Illuminate\Filesystem\Filesystem  $files
	 * @param  string  $path
	 * @return void
	 */
	public function __construct(Filesystem $files, $path)
	{
		$this->path = $path;
		$this->files = $files;
	}

	/**
	 * {@inheritDoc}
	 */
	public function open(string $path, string $name): bool
    {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function close(): bool
	{
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function read(string $id): false|string
    {
		if ($this->files->exists($path = $this->path.'/'.$id))
		{
			return $this->files->get($path);
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function write($id, $data): bool
	{
		try {
            $this->files->put($this->path.'/'.$id, $data, true);
        } catch (\Throwable) {
            return false;
        }

        return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function destroy(string $id): bool
	{
		try {
            $this->files->delete($this->path.'/'.$id);
        } catch (\Throwable) {
            return false;
        }

        return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function gc(int $max_lifetime): int|false
	{
		$files = Finder::create()
					->in($this->path)
					->files()
					->ignoreDotFiles(true)
					->date('<= now - '.$max_lifetime.' seconds');

		foreach ($files as $file)
		{
			$this->files->delete($file->getRealPath());
		}

        return count($files);
	}

}
