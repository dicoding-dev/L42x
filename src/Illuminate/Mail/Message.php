<?php namespace Illuminate\Mail;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Tested At:
 * {@see MailMessageTest}
 */
class Message {

	/**
	 * The Symfony message instance.
	 *
	 * @var Email
	 */
	protected Email $message;

	/**
	 * Create a new message instance.
	 *
	 * @param  Email  $message
	 * @return void
	 */
	public function __construct(Email $message)
	{
		$this->message = $message;
	}

	/**
	 * Add a "from" address to the message.
	 *
	 * @param  string  $address
	 * @param  ?string  $name
	 * @return $this
	 */
	public function from(string $address, ?string $name = null): self
	{
		$this->message->from(new Address($address, $name ?? ''));
		return $this;
	}

	/**
	 * Set the "sender" of the message.
	 *
	 * @param  string  $address
	 * @param  ?string  $name
	 * @return $this
	 */
	public function sender(string $address, ?string $name = null): self
	{
		$this->message->sender(new Address($address, $name));

		return $this;
	}

	/**
	 * Set the "return path" of the message.
	 *
	 * @param  string  $address
	 * @return $this
	 */
	public function returnPath(string $address): self
	{
		$this->message->returnPath($address);
		return $this;
	}

	/**
	 * Add a recipient to the message.
	 *
	 * @param  string|Address[]  $address
	 * @param  ?string  $name
	 * @return $this
	 */
	public function to(string|array $address, ?string $name = null): self
	{
		return $this->addAddresses($address, $name, 'To');
	}

	/**
	 * Add a carbon copy to the message.
	 *
	 * @param  string  $address
	 * @param  string  $name
	 * @return $this
	 */
	public function cc(string $address, ?string $name = null): self
	{
		return $this->addAddresses($address, $name, 'Cc');
	}

	/**
	 * Add a blind carbon copy to the message.
	 *
	 * @param  string  $address
	 * @param  string  $name
	 * @return $this
	 */
	public function bcc(string $address, ?string $name = null): self
	{
		return $this->addAddresses($address, $name, 'Bcc');
	}

	/**
	 * Add a reply to address to the message.
	 *
	 * @param  string  $address
	 * @param  string  $name
	 * @return $this
	 */
	public function replyTo(string $address, ?string $name = null): self
	{
		return $this->addAddresses($address, $name, 'ReplyTo');
	}

	/**
	 * Add a recipient to the message.
	 *
	 * @param  string|Address[]|array<email, string>  $address
	 * @param  string  $name
	 * @param  string  $type
	 * @return $this
	 */
	protected function addAddresses(string|array $address, ?string $name, string $type): self
	{
		if (is_array($address))
		{
            $type = lcfirst($type);
            $addresses = (new Collection($address))->map(function ($address, $key) {
                if (is_string($key) && is_string($address)) {
                    return new Address($key, $address);
                }

                if (is_array($address)) {
                    return new Address($address['email'] ?? $address['address'], $address['name'] ?? null);
                }

                if (is_null($address)) {
                    return new Address($key);
                }

                return $address;
            })->all();
            $this->message->$type(...$addresses);
		}
		else
		{
			$this->message->{"add{$type}"}(new Address($address, $name ?? ''));
		}

		return $this;
	}

	/**
	 * Set the subject of the message.
	 *
	 * @param  string  $subject
	 * @return $this
	 */
	public function subject(string $subject): self
	{
		$this->message->subject($subject);
		return $this;
	}

	/**
	 * Set the message priority level.
	 *
	 * @param  int  $level
	 * @return $this
	 */
	public function priority(int $level): self
	{
		$this->message->priority($level);
		return $this;
	}

	/**
	 * Attach a file to the message.
	 *
	 * @param  string  $file
	 * @param  array   $options
	 * @return $this
	 */
	public function attach(string $file, array $options = []): self
	{
        $this->message->attachFromPath($file, $options['as'] ?? null, $options['mime'] ?? null);
        return $this;
	}

	/**
	 * Attach in-memory data as an attachment.
	 *
	 * @param  string  $data
	 * @param  string  $name
	 * @param  array   $options
	 * @return $this
	 */
	public function attachData(string $data,  string $name, array $options = []): self
	{
        $this->message->attach($data, $name, $options['mime'] ?? null);
		return $this;
	}

	/**
	 * Embed a file in the message and get the CID.
	 *
	 * @param  string  $file
	 * @return string
	 */
	public function embed(string $file): string
	{
        $cid = Str::random(10);
        $this->message->embedFromPath($file, $cid);
		return "cid:$cid";
	}

	/**
	 * Embed in-memory data in the message and get the CID.
	 *
	 * @param  string  $data
	 * @param  string  $name
	 * @param  string  $contentType
	 * @return string
	 */
	public function embedData(string $data, string $name, ?string $contentType = null): string
	{
        $this->message->embed($data, $name, $contentType);
		return "cid:$name";
	}

	/**
	 * Get the underlying Symfony Message instance.
	 *
	 * @return Email
	 */
	public function getSymfonyMessage(): Email
	{
		return $this->message;
	}

	/**
	 * Dynamically pass missing methods to the Symfony Message instance.
	 *
	 * @param  string  $method
	 * @param  array   $parameters
	 * @return mixed
	 */
	public function __call(string $method, array $parameters)
	{
		$callable = [$this->message, $method];

		return call_user_func_array($callable, $parameters);
	}

}
