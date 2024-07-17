<?php namespace Illuminate\Mail;

use Closure;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Str;
use Illuminate\Log\Writer;
use Illuminate\View\Factory;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Container\Container;
use Illuminate\Support\SerializableClosure;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Tested At:
 * {@see MailMailerTest}
 */
class Mailer {

	/**
	 * The view factory instance.
	 */
	protected Factory $views;

	/**
	 * The Symfony Transport instance.
	 */
	protected TransportInterface $transport;

	/**
	 * The event dispatcher instance.
	 */
	protected ?Dispatcher $events;

	/**
	 * The global from address and name.
	 */
	protected array $from;

	/**
	 * The log writer instance.
	 */
	protected Writer $logger;

	/**
	 * The IoC container instance.
	 */
	protected Container $container;

	/*
	 * The QueueManager instance.
	 */
	protected QueueManager $queue;

	/**
	 * Indicates if the actual sending is disabled.
	 */
	protected bool $pretending = false;

	/**
	 * Array of failed recipients.
	 */
	protected array $failedRecipients = [];

	/**
	 * Array of parsed views containing html and text view name.
	 */
	protected array $parsedViews = [];

	/**
	 * Create a new Mailer instance.
	 */
	public function __construct(Factory $views, TransportInterface $transport, ?Dispatcher $events = null)
	{
		$this->views = $views;
		$this->transport = $transport;
		$this->events = $events;
	}

    /**
     * Set the global from address and name.
     * @param string $address
     * @param ?string $name
     * @return void
     */
	public function alwaysFrom(string $address, ?string $name = null): void
	{
		$this->from = compact('address', 'name');
	}

	/**
	 * Send a new message when only a plain part.
	 *
	 * @param  string  $view
	 * @param  array   $data
	 * @param  mixed   $callback
	 * @return int
	 */
	public function plain(string $view, array $data, mixed $callback): int
	{
		return $this->send(['text' => $view], $data, $callback);
	}

	/**
	 * Send a new message using a view.
	 *
	 * @param array|string $view
	 * @param  array  $data
	 * @param string|\Closure $callback
	 * @return void
	 */
	public function send(array|string $view, array $data, string|Closure $callback): void
	{
		// First we need to parse the view, which could either be a string or an array
		// containing both an HTML and plain text versions of the view which should
		// be used when sending an e-mail. We will extract both of them out here.
		list($view, $plain) = $this->parseView($view);

		$data['message'] = $message = $this->createMessage();

		$this->callMessageBuilder($callback, $message);

		// Once we have retrieved the view content for the e-mail we will set the body
		// of this message using the HTML type, which will provide a simple wrapper
		// to creating view based emails that are able to receive arrays of data.
		$this->addContent($message, $view, $plain, $data);

		$message = $message->getSymfonyMessage();
		$this->sendSymfonyMessage($message);
	}

	/**
	 * Queue a new e-mail message for sending.
	 *
	 * @param array|string $view
	 * @param  array   $data
	 * @param string|\Closure $callback
	 * @param string|null $queue
	 * @return mixed
	 */
	public function queue(array|string $view, array $data, string|Closure $callback, ?string $queue = null): mixed
    {
		$callback = $this->buildQueueCallable($callback);

		return $this->queue->push('mailer@handleQueuedMessage', compact('view', 'data', 'callback'), $queue);
	}

	/**
	 * Queue a new e-mail message for sending on the given queue.
	 *
	 * @param string $queue
	 * @param array|string $view
	 * @param  array   $data
	 * @param string|\Closure $callback
	 * @return mixed
	 */
	public function queueOn(string $queue, array|string $view, array $data, string|Closure $callback): mixed
    {
		return $this->queue($view, $data, $callback, $queue);
	}

	/**
	 * Queue a new e-mail message for sending after (n) seconds.
	 *
	 * @param int $delay
	 * @param  string|array  $view
	 * @param  array  $data
	 * @param  \Closure|string  $callback
	 * @param  ?string  $queue
	 * @return mixed
	 */
	public function later(int $delay, string|array $view, array $data, Closure|string $callback, ?string $queue = null): mixed
    {
		$callback = $this->buildQueueCallable($callback);

		return $this->queue->later($delay, 'mailer@handleQueuedMessage', compact('view', 'data', 'callback'), $queue);
	}

	/**
	 * Queue a new e-mail message for sending after (n) seconds on the given queue.
	 *
	 * @param  string  $queue
	 * @param  int  $delay
	 * @param  string|array  $view
	 * @param  array  $data
	 * @param  \Closure|string  $callback
	 * @return mixed
	 */
	public function laterOn(string $queue, int $delay, string|array $view, array $data, Closure|string $callback): mixed
    {
		return $this->later($delay, $view, $data, $callback, $queue);
	}

	/**
	 * Build the callable for a queued e-mail job.
	 *
	 * @param  mixed  $callback
	 * @return mixed
	 */
	protected function buildQueueCallable(mixed $callback): mixed
    {
		if (!$callback instanceof Closure) {
            return $callback;
        }

		return serialize(new SerializableClosure($callback));
	}

	/**
	 * Handle a queued e-mail message job.
	 *
	 * @param  \Illuminate\Queue\Jobs\Job  $job
	 * @param  array  $data
	 * @return void
	 */
	public function handleQueuedMessage(Job $job, array $data): void
    {
		$this->send($data['view'], $data['data'], $this->getQueuedCallable($data));
		$job->delete();
	}

	/**
	 * Get the true callable for a queued e-mail message.
	 *
	 * @param  array  $data
	 * @return mixed
	 */
	protected function getQueuedCallable(array $data): mixed
    {
		if (Str::contains($data['callback'], 'SerializableClosure'))
		{
			return with(unserialize($data['callback']))->getClosure();
		}

		return $data['callback'];
	}

	/**
	 * Add the content to a given message.
	 *
	 * @param  \Illuminate\Mail\Message  $message
	 * @param  string  $view
	 * @param  string  $plain
	 * @param  array   $data
	 * @return void
	 */
	protected function addContent(Message $message, ?string $view, ?string $plain, array $data): void
    {
		if ($view !== null)
		{
			$message->html($this->renderView($view, $data));
		}

		if ($plain !== null)
		{
			$message->text($this->renderView($plain, $data));
		}
	}

    protected function renderView(string $view, array $data): string
    {
        return $this->views->make($view, $data)->render();
    }

	/**
	 * Parse the given view name or array.
	 *
	 * @param  string|array  $view
	 * @return array
	 *
	 * @throws \InvalidArgumentException
	 */
	protected function parseView(string|array $view): array
    {
		if (is_string($view)) {
            return [$view, null];
        }

		// If the given view is an array with numeric keys, we will just assume that
		// both a "pretty" and "plain" view were provided, so we will return this
		// array as is, since must should contain both views with numeric keys.
		if (is_array($view) && isset($view[0]))
		{
			return [$view[0], $view[1] ?? null];
		}

		// If the view is an array, but doesn't contain numeric keys, we will assume
		// the the views are being explicitly specified and will extract them via
		// named keys instead, allowing the developers to use one or the other.
		elseif (is_array($view))
		{
			return [
                $view['html'] ?? null,
                $view['text'] ?? null
            ];
		}

		throw new \InvalidArgumentException("Invalid view.");
	}

    /**
     * Send a Symfony Message.
     */
    protected function sendSymfonyMessage(Email $message): void
    {
        $this->events?->fire('mailer.sending', [$message]);

        if (!$this->pretending)
        {
            $this->transport->send($message, Envelope::create($message));
        }
        elseif (isset($this->logger))
        {
            $this->logMessage($message);
        }
    }

	/**
	 * Log that a message was sent.
	 */
	protected function logMessage(Email $message): void
	{
		$emails = implode(', ', array_map(
            fn (Address $address) => $address->getAddress(),
            $message->getTo()
        ));

		$this->logger->info("Pretending to mail message to: {$emails}");
	}

	/**
	 * Call the provided message builder.
	 *
	 * @param string|\Closure $callback
	 * @param \Illuminate\Mail\Message $message
	 * @return mixed
	 */
	protected function callMessageBuilder(string|Closure $callback, Message $message): mixed
    {
		if ($callback instanceof Closure)
		{
			return call_user_func($callback, $message);
		}
		else
		{
			return $this->container[$callback]->mail($message);
		}
	}

	/**
	 * Create a new message instance.
	 *
	 * @return \Illuminate\Mail\Message
	 */
	protected function createMessage(): Message
    {
		$message = new Message(new Email());

		// If a global from address has been specified we will set it on every message
		// instances so the developer does not have to repeat themselves every time
		// they create a new message. We will just go ahead and push the address.
		if (isset($this->from['address']))
		{
			$message->from($this->from['address'], $this->from['name']);
		}

		return $message;
	}

	/**
	 * Tell the mailer to not really send messages.
	 *
	 * @param  bool  $value
	 * @return void
	 */
	public function pretend(bool $value = true): void
    {
		$this->pretending = $value;
	}

	/**
	 * Check if the mailer is pretending to send messages.
	 *
	 * @return bool
	 */
	public function isPretending(): bool
    {
		return $this->pretending;
	}

	/**
	 * Get the view factory instance.
	 *
	 * @return \Illuminate\View\Factory
	 */
	public function getViewFactory(): Factory
    {
		return $this->views;
	}

	/**
	 * Get the Symfony Transport instance.
	 */
	public function getSymfonyTransport(): TransportInterface
	{
		return $this->transport;
	}

	/**
	 * Get the array of failed recipients.
	 *
	 * @return array
     * @deprecated
	 */
	public function failures(): array
    {
		return $this->failedRecipients;
	}

	/**
	 * Set the Symfony Transport instance.
	 */
	public function setSymfonyTransport(TransportInterface $transport): void
	{
		$this->transport = $transport;
	}

	/**
	 * Set the log writer instance.
	 *
	 * @param  \Illuminate\Log\Writer  $logger
	 * @return $this
	 */
	public function setLogger(Writer $logger): static
    {
		$this->logger = $logger;

		return $this;
	}

	/**
	 * Set the queue manager instance.
	 *
	 * @param  \Illuminate\Queue\QueueManager  $queue
	 * @return $this
	 */
	public function setQueue(QueueManager $queue): static
    {
		$this->queue = $queue;

		return $this;
	}

	/**
	 * Set the IoC container instance.
	 *
	 * @param  \Illuminate\Container\Container  $container
	 * @return void
	 */
	public function setContainer(Container $container): void
    {
		$this->container = $container;
	}

}
