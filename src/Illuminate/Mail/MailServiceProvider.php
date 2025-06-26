<?php namespace Illuminate\Mail;

use Illuminate\Foundation\Application;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
//use Symfony\Component\HttpClient\HttpClient;
//use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
//use Symfony\Contracts\HttpClient\HttpClientInterface;

class MailServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = true;

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register(): void
	{
		$me = $this;

		$this->app->bindShared('mailer', function($app) use ($me)
		{
			$me->registerSymfonyMailer();

			// Once we have create the mailer instance, we will set a container instance
			// on the mailer. This allows us to resolve mailer classes via containers
			// for maximum testability on said classes instead of passing Closures.
			$mailer = new Mailer(
				$app['view'], $app['symfony.transport'], $app['events']
			);

			$this->setMailerDependencies($mailer, $app);

			// If a "from" address is set, we will set it on the mailer so that all mail
			// messages sent by the applications will utilize the same "from" address
			// on each one, which makes the developer's life a lot more convenient.
			$from = $app['config']['mail.from'];

			if (is_array($from) && isset($from['address']))
			{
				$mailer->alwaysFrom($from['address'], $from['name']);
			}

			// Here we will determine if the mailer should be in "pretend" mode for this
			// environment, which will simply write out e-mail to the logs instead of
			// sending it over the web, which is useful for local dev environments.
			$pretend = $app['config']->get('mail.pretend', false);

			$mailer->pretend($pretend);

			return $mailer;
		});
	}

	/**
	 * Set a few dependencies on the mailer instance.
	 *
	 * @param  \Illuminate\Mail\Mailer  $mailer
	 * @param  \Illuminate\Foundation\Application  $app
	 * @return void
	 */
	protected function setMailerDependencies(Mailer $mailer, Application $app): void
	{
		$mailer->setContainer($app);

		if ($app->bound('log'))
		{
			$mailer->setLogger($app['log']);
		}

		if ($app->bound('queue'))
		{
			$mailer->setQueue($app['queue']);
		}
	}

    public function registerSymfonyMailer(): void
    {
        $config = $this->app['config']['mail'];

        switch ($config['driver'])
        {
            case 'smtp':
                $this->registerSmtpTransport($config);
                break;
            case 'sendmail':
                $this->registerSendmailTransport($config);
                break;
            case 'mail':
                $this->registerMailTransport($config);
                break;
//            case 'mailgun':
//                $this->registerMailgunTransport($config);
//                break;
//            case 'mandrill':
//                $this->registerMandrillTransport($config);
//                break;
            case 'log':
                $this->registerLogTransport($config);
                break;
            default:
                throw new \InvalidArgumentException('Invalid mail driver.');
        }
    }

	/**
	 * Register the SMTP symfony Transport instance.
	 *
	 * @param  array  $config
	 * @return void
	 */
	protected function registerSmtpTransport(array $config): void
	{
		$this->app['symfony.transport'] = $this->app->share(function($app) use ($config)
		{
            $factory = new EsmtpTransportFactory();

            $scheme = $config['scheme'] ?? null;

            if (! $scheme) {
                $scheme = ! empty($config['encryption']) && $config['encryption'] === 'tls'
                    ? (($config['port'] == 465) ? 'smtps' : 'smtp')
                    : '';
            }

            /** @var EsmtpTransport $transport */
            $transport = $factory->create(new Dsn(
                $scheme,
                $config['host'],
                $config['username'] ?? null,
                $config['password'] ?? null,
                $config['port'] ?? null,
                $config
            ));

            $stream = $transport->getStream();

            if ($stream instanceof SocketStream) {
                if (isset($config['source_ip'])) {
                    $stream->setSourceIp($config['source_ip']);
                }

                if (isset($config['timeout'])) {
                    $stream->setTimeout($config['timeout']);
                }
            }

			return $transport;
		});
	}

	/**
	 * Register the Sendmail Symfony Transport instance.
	 *
	 * @param  array  $config
	 * @return void
	 */
	protected function registerSendmailTransport(array $config): void
	{
		$this->app['symfony.transport'] = $this->app->share(fn($app) => new SendmailTransport(
            $config['path'] ?? $app['config']->get('mail.sendmail')
        ));
	}

	/**
	 * Register the Mail Symfony Transport instance.
	 *
	 * @param  array  $config
	 * @return void
	 */
	protected function registerMailTransport(array $config): void
	{
		$this->app['symfony.transport'] = $this->app->share(fn() => new SendmailTransport());
	}

	/**
	 * Register the Mailgun Symfony Transport instance.
	 *
	 * @param  array  $config
	 * @return void
	 */
//	protected function registerMailgunTransport(array $config): void
//	{
//		$this->app->bindShared('symfony.transport', function() use ($config)
//		{
//            $factory = new MailgunTransportFactory(null, $this->getHttpClient($config));
//
//            if (! isset($config['secret'])) {
//                $config = $this->app['config']->get('services.mailgun', []);
//            }
//
//            return $factory->create(new Dsn(
//                'mailgun+'.($config['scheme'] ?? 'https'),
//                $config['endpoint'] ?? 'default',
//                $config['secret'],
//                $config['domain']
//            ));
//		});
//	}

	/**
	 * Register the "Log" Symfony Transport instance.
	 *
	 * @param  array  $config
	 * @return void
	 */
	protected function registerLogTransport(array $config): void
	{
		$this->app->bindShared('symfony.transport', fn($app) => new LogTransport($app->make('Psr\Log\LoggerInterface')));
	}

//    /**
//     * Get a configured Symfony HTTP client instance.
//     *
//     * @return \Symfony\Contracts\HttpClient\HttpClientInterface|null
//     */
//    protected function getHttpClient(array $config): ?HttpClientInterface
//    {
//        $clientOptions = $config['client'] ?? false;
//        if ($clientOptions) {
//            $maxHostConnections = Arr::pull($clientOptions, 'max_host_connections', 6);
//            $maxPendingPushes = Arr::pull($clientOptions, 'max_pending_pushes', 50);
//
//            return HttpClient::create($clientOptions, $maxHostConnections, $maxPendingPushes);
//        }
//
//        return null;
//    }

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides(): array
	{
		return ['mailer', 'symfony.transport'];
	}

}
