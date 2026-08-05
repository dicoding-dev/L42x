<?php namespace Illuminate\Queue\Connectors;

use Aws\Sqs\SqsClient;
use Illuminate\Queue\SqsQueue;

class SqsConnector implements ConnectorInterface {

	/**
	 * Establish a queue connection.
	 *
	 * The client is built the AWS SDK v3 way (explicit `version` + nested
	 * `credentials`); the removed v2 `SqsClient::factory()` is no longer used.
	 *
	 * @param  array  $config
	 * @return \Illuminate\Queue\QueueInterface
	 */
	public function connect(array $config)
	{
		$clientConfig = array(
			'region'  => isset($config['region']) ? $config['region'] : 'us-east-1',
			'version' => isset($config['version']) ? $config['version'] : 'latest',
		);

		// Custom endpoint for SQS-compatible services (ElasticMQ/LocalStack) in
		// local/dev; omitted in production so the SDK targets real AWS.
		if ( ! empty($config['endpoint']))
		{
			$clientConfig['endpoint'] = $config['endpoint'];
		}

		// Credentials are optional: when absent the SDK falls back to its
		// default provider chain (env vars, IAM instance/task role).
		if ( ! empty($config['key']) && ! empty($config['secret']))
		{
			$clientConfig['credentials'] = array(
				'key'    => $config['key'],
				'secret' => $config['secret'],
			);
		}

		return new SqsQueue(
			new SqsClient($clientConfig),
			$config['queue'],
			isset($config['prefix']) ? $config['prefix'] : ''
		);
	}

}
