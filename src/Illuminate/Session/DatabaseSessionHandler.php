<?php namespace Illuminate\Session;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

class DatabaseSessionHandler implements \SessionHandlerInterface, ExistenceAwareInterface {

	/**
	 * The database connection instance.
	 */
	protected Connection $connection;

	/**
	 * The name of the session table.
	 */
	protected string $table;

	/**
	 * The existence state of the session.
	 */
	protected bool $exists;

	/**
	 * Create a new database session handler instance.
	 *
	 * @param  \Illuminate\Database\Connection  $connection
	 * @param  string  $table
	 * @return void
	 */
	public function __construct(Connection $connection, $table)
	{
		$this->table = $table;
		$this->connection = $connection;
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
	public function read(string $id): string|false
	{
		$session = (object) $this->getQuery()->find($id);

		if (isset($session->payload))
		{
			$this->exists = true;

			return base64_decode((string) $session->payload);
		}

        return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function write(string $id, string $data): bool
	{
		if ($this->exists)
		{
			$this->getQuery()->where('id', $id)->update([
				'payload' => base64_encode($data), 'last_activity' => time(),
			]);
		}
		else
		{
			$this->getQuery()->insert([
                'id' => $id, 'payload' => base64_encode($data), 'last_activity' => time(),
			]);
		}

		$this->exists = true;

        return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function destroy(string $id): bool
	{
		try {
            $this->getQuery()->where('id', $id)->delete();
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
		return $this->getQuery()->where('last_activity', '<=', time() - $max_lifetime)->delete();
	}

	/**
	 * Get a fresh query builder instance for the table.
	 *
	 * @return Builder
	 */
	protected function getQuery(): Builder
    {
		return $this->connection->table($this->table);
	}

	/**
	 * Set the existence state for the session.
	 *
	 * @param  bool  $value
	 * @return $this
	 */
	public function setExists($value)
	{
		$this->exists = $value;

		return $this;
	}

}
