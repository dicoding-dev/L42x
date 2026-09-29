<?php

use Illuminate\Encryption\Encrypter;

/**
 * ponytail: after the queue swap (task 4.3) v13 queues closures as CallQueuedClosure;
 * this class only drains closure jobs that L4.2 queued before the deploy (their payload
 * names "IlluminateQueueClosure", which v13 fires like any "Class@fire" job). Remove
 * once the queues are drained at cutover (task 5.1).
 */
class IlluminateQueueClosure {

	/**
	 * The encrypter instance.
	 *
	 * @var \Illuminate\Encryption\Encrypter  $crypt
	 */
	protected $crypt;

	/**
	 * Create a new queued Closure job.
	 *
	 * @param  \Illuminate\Encryption\Encrypter  $crypt
	 * @return void
	 */
	public function __construct(Encrypter $crypt)
	{
		$this->crypt = $crypt;
	}

	/**
	 * Fire the Closure based queue job.
	 *
	 * @param  \Illuminate\Queue\Jobs\Job  $job
	 * @param  array  $data
	 * @return void
	 */
	public function fire($job, $data)
	{
		$closure = unserialize($this->crypt->decrypt($data['closure']));

		$closure($job);
	}

}
