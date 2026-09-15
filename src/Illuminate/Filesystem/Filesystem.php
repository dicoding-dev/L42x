<?php namespace Illuminate\Filesystem;

use FilesystemIterator;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Traits\Conditionable;

class Filesystem {

	use Macroable, Conditionable;

	/**
	 * Determine if a file exists.
	 *
	 * @param  string  $path
	 * @return bool
	 */
	public function exists($path)
	{
		return file_exists($path);
	}

	/**
	 * Get the contents of a file.
	 *
	 * @param  string  $path
	 * @return string
	 *
	 * @throws FileNotFoundException
	 */
	public function get($path, $lock = false)
	{
		if ($this->isFile($path)) return file_get_contents($path, $lock ? LOCK_SH : 0);

		throw new FileNotFoundException("File does not exist at path {$path}");
	}

	/**
	 * Get the returned value of a file.
	 *
	 * @param  string  $path
	 * @return mixed
	 *
	 * @throws FileNotFoundException
	 */
	public function getRequire($path, array $data = [])
	{
		if ($this->isFile($path)) {
			extract($data);
			return require $path;
		}

		throw new FileNotFoundException("File does not exist at path {$path}");
	}

	/**
	 * Require the given file once.
	 *
	 * @param  string  $file
	 * @return mixed
	 */
	public function requireOnce($file, array $data = [])
	{
		extract($data);
		require_once $file;
	}

	/**
	 * Write the contents of a file.
	 *
	 * @param  string  $path
	 * @param  string  $contents
	 * @param  bool  $lock
	 * @return int
	 */
	public function put($path, $contents, $lock = false)
	{
		return file_put_contents($path, $contents, $lock ? LOCK_EX : 0);
	}

	/**
	 * Prepend to a file.
	 *
	 * @param  string  $path
	 * @param  string  $data
	 * @return int
	 */
	public function prepend($path, $data)
	{
		if ($this->exists($path))
		{
			return $this->put($path, $data.$this->get($path));
		}

		return $this->put($path, $data);
	}

	/**
	 * Append to a file.
	 *
	 * @param  string  $path
	 * @param  string  $data
	 * @return int
	 */
	public function append($path, $data, $lock = false)
	{
		return file_put_contents($path, $data, FILE_APPEND | ($lock ? LOCK_EX : 0));
	}

	/**
	 * Delete the file at a given path.
	 *
	 * @param  string|array  $paths
	 * @return bool
	 */
	public function delete($paths)
	{
		$paths = is_array($paths) ? $paths : func_get_args();

		$success = true;

		foreach ($paths as $path) { if ( ! @unlink($path)) $success = false; }

		return $success;
	}

	/**
	 * Move a file to a new location.
	 *
	 * @param  string  $path
	 * @param  string  $target
	 * @return bool
	 */
	public function move($path, $target)
	{
		return rename($path, $target);
	}

	/**
	 * Copy a file to a new location.
	 *
	 * @param  string  $path
	 * @param  string  $target
	 * @return bool
	 */
	public function copy($path, $target)
	{
		return copy($path, $target);
	}

	/**
	 * Extract the file name from a file path.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function name($path)
	{
		return pathinfo($path, PATHINFO_FILENAME);
	}

	/**
	 * Extract the file extension from a file path.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function extension($path)
	{
		return pathinfo($path, PATHINFO_EXTENSION);
	}

	/**
	 * Get the file type of a given file.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function type($path)
	{
		return filetype($path);
	}

	/**
	 * Get the file size of a given file.
	 *
	 * @param  string  $path
	 * @return int
	 */
	public function size($path)
	{
		return filesize($path);
	}

	/**
	 * Get the file's last modification time.
	 *
	 * @param  string  $path
	 * @return int
	 */
	public function lastModified($path)
	{
		return filemtime($path);
	}

	/**
	 * Determine if the given path is a directory.
	 *
	 * @param  string  $directory
	 * @return bool
	 */
	public function isDirectory($directory)
	{
		return is_dir($directory);
	}

	/**
	 * Determine if the given path is writable.
	 *
	 * @param  string  $path
	 * @return bool
	 */
	public function isWritable($path)
	{
		return is_writable($path);
	}

	/**
	 * Determine if the given path is a file.
	 *
	 * @param  string  $file
	 * @return bool
	 */
	public function isFile($file)
	{
		return is_file($file);
	}

	/**
	 * Find path names matching a given pattern.
	 *
	 * @param  string  $pattern
	 * @param  int     $flags
	 * @return array
	 */
	public function glob($pattern, $flags = 0)
	{
		return glob($pattern, $flags);
	}

	/**
	 * Get an array of all files in a directory.
	 *
	 * @param  string  $directory
	 * @return array
	 */
	public function files($directory, $hidden = false)
	{
		$pattern = $hidden ? $directory.'/{,.}*' : $directory.'/*';
		$flags = $hidden ? GLOB_BRACE : 0;
		$glob = glob($pattern, $flags);

		if ($glob === false) return array();

		return array_filter($glob, function($file)
		{
			return filetype($file) == 'file';
		});
	}

	/**
	 * Get all of the files from the given directory (recursive).
	 *
	 * @param  string  $directory
	 * @return array
	 */
	public function allFiles($directory, $hidden = false)
	{
		$finder = Finder::create()->files()->ignoreDotFiles(! $hidden)->in($directory);

		return iterator_to_array($finder, false);
	}

	/**
	 * Get all of the directories within a given directory.
	 *
	 * @param  string  $directory
	 * @return array
	 */
	public function directories($directory, $depth = 0)
	{
		$directories = array();

		foreach (Finder::create()->in($directory)->directories()->depth($depth === 0 ? 0 : '>= 0') as $dir)
		{
			$directories[] = $dir->getPathname();
		}

		return $directories;
	}

	/**
	 * Create a directory.
	 *
	 * @param  string  $path
	 * @param  int     $mode
	 * @param  bool    $recursive
	 * @param  bool    $force
	 * @return bool
	 */
	public function makeDirectory($path, $mode = 0755, $recursive = false, $force = false)
	{
		if ($force)
		{
			return @mkdir($path, $mode, $recursive);
		}

		return mkdir($path, $mode, $recursive);
	}

	/**
	 * Copy a directory from one location to another.
	 *
	 * @param  string  $directory
	 * @param  string  $destination
	 * @param  int     $options
	 * @return bool
	 */
	public function copyDirectory($directory, $destination, $options = null)
	{
		if ( ! $this->isDirectory($directory)) return false;

		$options = $options ?: FilesystemIterator::SKIP_DOTS;

		// If the destination directory does not actually exist, we will go ahead and
		// create it recursively, which just gets the destination prepared to copy
		// the files over. Once we make the directory we'll proceed the copying.
		if ( ! $this->isDirectory($destination))
		{
			$this->makeDirectory($destination, 0777, true);
		}

		$items = new FilesystemIterator($directory, $options);

		foreach ($items as $item)
		{
			// As we spin through items, we will check to see if the current file is actually
			// a directory or a file. When it is actually a directory we will need to call
			// back into this function recursively to keep copying these nested folders.
			$target = $destination.'/'.$item->getBasename();

			if ($item->isDir())
			{
				$path = $item->getPathname();

				if ( ! $this->copyDirectory($path, $target, $options)) return false;
			}

			// If the current items is just a regular file, we will just copy this to the new
			// location and keep looping. If for some reason the copy fails we'll bail out
			// and return false, so the developer is aware that the copy process failed.
			else
			{
				if ( ! $this->copy($item->getPathname(), $target)) return false;
			}
		}

		return true;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * The directory itself may be optionally preserved.
	 *
	 * @param  string  $directory
	 * @param  bool    $preserve
	 * @return bool
	 */
	public function deleteDirectory($directory, $preserve = false)
	{
		if ( ! $this->isDirectory($directory)) return false;

		$items = new FilesystemIterator($directory);

		foreach ($items as $item)
		{
			// If the item is a directory, we can just recurse into the function and
			// delete that sub-directory otherwise we'll just delete the file and
			// keep iterating through each file until the directory is cleaned.
			if ($item->isDir())
			{
				$this->deleteDirectory($item->getPathname());
			}

			// If the item is just a file, we can go ahead and delete it since we're
			// just looping through and waxing all of the files in this directory
			// and calling directories recursively, so we delete the real path.
			else
			{
				$this->delete($item->getPathname());
			}
		}

		if ( ! $preserve) @rmdir($directory);

		return true;
	}

	/**
	 * Empty the specified directory of all files and folders.
	 *
	 * @param  string  $directory
	 * @return bool
	 */
	public function cleanDirectory($directory)
	{
		return $this->deleteDirectory($directory, true);
	}

	/**
	 * Determine if a file or directory is missing.
	 *
	 * @param  string  $path
	 * @return bool
	 */
	public function missing($path)
	{
		return ! $this->exists($path);
	}

	/**
	 * Get the contents of a file as decoded JSON.
	 *
	 * @param  string  $path
	 * @param  int  $flags
	 * @param  bool  $lock
	 * @return array
	 */
	public function json($path, $flags = 0, $lock = false)
	{
		return json_decode($this->get($path, $lock), true, 512, $flags);
	}

	/**
	 * Get the contents of a file with shared access.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function sharedGet($path)
	{
		return file_get_contents($path, LOCK_SH);
	}

	/**
	 * Get the MD5 hash of the file at the given path.
	 *
	 * @param  string  $path
	 * @param  string  $algorithm
	 * @return string
	 */
	public function hash($path, $algorithm = 'md5')
	{
		return hash_file($algorithm, $path);
	}

	/**
	 * Write the contents of a file, replacing it atomically.
	 *
	 * @param  string  $path
	 * @param  string  $content
	 * @param  int|null  $mode
	 * @return void
	 */
	public function replace($path, $content, $mode = null)
	{
		file_put_contents($path, $content);

		if ($mode !== null) {
			chmod($path, $mode);
		}
	}

	/**
	 * Replace a given string within a file.
	 *
	 * @param  string|array  $search
	 * @param  string|array  $replace
	 * @param  string  $path
	 * @return void
	 */
	public function replaceInFile($search, $replace, $path)
	{
		file_put_contents($path, str_replace($search, $replace, file_get_contents($path)));
	}

	/**
	 * Set the mode of a file or directory.
	 *
	 * @param  string  $path
	 * @param  int|null  $mode
	 * @return mixed
	 */
	public function chmod($path, $mode = null)
	{
		return chmod($path, $mode ?? 0664);
	}

	/**
	 * Create a symlink to a target file.
	 *
	 * @param  string  $target
	 * @param  string  $link
	 * @return void
	 */
	public function link($target, $link)
	{
		symlink($target, $link);
	}

	/**
	 * Create a relative symlink to a target file.
	 *
	 * @param  string  $target
	 * @param  string  $link
	 * @return void
	 */
	public function relativeLink($target, $link)
	{
		$relative = $this->getRelativePath($target, $link);

		symlink($relative, $link);
	}

	/**
	 * Get the basename of a file path.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function basename($path)
	{
		return basename($path);
	}

	/**
	 * Get the dirname of a file path.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function dirname($path)
	{
		return dirname($path);
	}

	/**
	 * Guess the file extension from the mime-type of a given file.
	 *
	 * @param  string  $path
	 * @return string|null
	 */
	public function guessExtension($path)
	{
		$mime = $this->mimeType($path);

		$extensions = [
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			'image/svg+xml' => 'svg',
			'text/plain' => 'txt',
			'text/html' => 'html',
			'text/css' => 'css',
			'application/javascript' => 'js',
			'application/json' => 'json',
			'application/pdf' => 'pdf',
			'application/zip' => 'zip',
		];

		return $extensions[$mime] ?? null;
	}

	/**
	 * Get the MIME type of a file.
	 *
	 * @param  string  $path
	 * @return string|false
	 */
	public function mimeType($path)
	{
		return mime_content_type($path);
	}

	/**
	 * Determine if the given path is readable.
	 *
	 * @param  string  $path
	 * @return bool
	 */
	public function isReadable($path)
	{
		return is_readable($path);
	}

	/**
	 * Determine if the given directory is empty.
	 *
	 * @param  string  $directory
	 * @return bool
	 */
	public function isEmptyDirectory($directory)
	{
		return count(scandir($directory)) === 2; // . and ..
	}

	/**
	 * Determine if two files have the same hash.
	 *
	 * @param  string  $path1
	 * @param  string  $path2
	 * @return bool
	 */
	public function hasSameHash($path1, $path2)
	{
		return md5_file($path1) === md5_file($path2);
	}

	/**
	 * Ensure a directory exists.
	 *
	 * @param  string  $path
	 * @param  int  $mode
	 * @param  bool  $recursive
	 * @return void
	 */
	public function ensureDirectoryExists($path, $mode = 0755, $recursive = true)
	{
		if (! $this->isDirectory($path)) {
			$this->makeDirectory($path, $mode, $recursive);
		}
	}

	/**
	 * Move a directory.
	 *
	 * @param  string  $from
	 * @param  string  $to
	 * @param  bool  $overwrite
	 * @return bool
	 */
	public function moveDirectory($from, $to, $overwrite = false)
	{
		if ($overwrite && $this->isDirectory($to)) {
			$this->deleteDirectory($to);
		}

		return @rename($from, $to);
	}

	/**
	 * Get all of the directories within a given directory (recursive).
	 *
	 * @param  string  $directory
	 * @return array
	 */
	public function allDirectories($directory)
	{
		return $this->directories($directory, -1);
	}

	/**
	 * Delete all of the directories within a given directory.
	 *
	 * @param  string  $directory
	 * @return bool
	 */
	public function deleteDirectories($directory)
	{
		$allDirectories = $this->directories($directory, -1);

		if (! empty($allDirectories)) {
			foreach ($allDirectories as $dir) {
				@rmdir($dir);
			}

			return true;
		}

		return false;
	}

	// ponytail: add lines() when LazyCollection arrives (Wave 4+)
}
