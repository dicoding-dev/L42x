<?php namespace Illuminate\Auth\Reminders;

use Closure;
use Illuminate\Mail\Mailer;
use Illuminate\Auth\UserProviderInterface;

class PasswordBroker {

	/**
	 * Constant representing a successfully sent reminder.
	 *
	 * @var int
	 */
	final const REMINDER_SENT = 'reminders.sent';

	/**
	 * Constant representing a successfully reset password.
	 *
	 * @var int
	 */
	final const PASSWORD_RESET = 'reminders.reset';

	/**
	 * Constant representing the user not found response.
	 *
	 * @var int
	 */
	final const INVALID_USER = 'reminders.user';

	/**
	 * Constant representing an invalid password.
	 *
	 * @var int
	 */
	final const INVALID_PASSWORD = 'reminders.password';

	/**
	 * Constant representing an invalid token.
	 *
	 * @var int
	 */
	final const INVALID_TOKEN = 'reminders.token';

	/**
	 * The password reminder repository.
	 *
	 * @var \Illuminate\Auth\Reminders\ReminderRepositoryInterface  $reminders
	 */
	protected ReminderRepositoryInterface $reminders;

	/**
	 * The user provider implementation.
	 *
	 * @var \Illuminate\Auth\UserProviderInterface
	 */
	protected UserProviderInterface $users;

	/**
	 * The mailer instance.
	 *
	 * @var \Illuminate\Mail\Mailer
	 */
	protected Mailer $mailer;

	/**
	 * The view of the password reminder e-mail.
	 *
	 * @var string
	 */
	protected string $reminderView;

	/**
	 * The custom password validator callback.
	 *
	 * @var \Closure
	 */
	protected Closure $passwordValidator;

	/**
	 * Create a new password broker instance.
	 *
	 * @param  \Illuminate\Auth\Reminders\ReminderRepositoryInterface  $reminders
	 * @param  \Illuminate\Auth\UserProviderInterface  $users
	 * @param  \Illuminate\Mail\Mailer  $mailer
	 * @param string $reminderView
	 * @return void
	 */
	public function __construct(ReminderRepositoryInterface $reminders,
                                UserProviderInterface       $users,
                                Mailer                      $mailer,
                                string                      $reminderView)
	{
		$this->users = $users;
		$this->mailer = $mailer;
		$this->reminders = $reminders;
		$this->reminderView = $reminderView;
	}

	/**
	 * Send a password reminder to a user.
	 *
	 * @param  array     $credentials
	 * @param  \Closure  $callback
	 * @return string
	 */
	public function remind(array $credentials, Closure $callback = null): string
	{
		// First we will check to see if we found a user at the given credentials and
		// if we did not we will redirect back to this current URI with a piece of
		// "flash" data in the session to indicate to the developers the errors.
		$user = $this->getUser($credentials);

		if ($user === null)
		{
			return self::INVALID_USER;
		}

		// Once we have the reminder token, we are ready to send a message out to the
		// user with a link to reset their password. We will then redirect back to
		// the current URI having nothing set in the session to indicate errors.
		$token = $this->reminders->create($user);

		$this->sendReminder($user, $token, $callback);

		return self::REMINDER_SENT;
	}

	/**
	 * Send the password reminder e-mail.
	 *
	 * @param  \Illuminate\Auth\Reminders\RemindableInterface  $user
	 * @param string $token
	 * @param  Closure  $callback
	 * @return void
	 */
	public function sendReminder(RemindableInterface $user, string $token, Closure $callback = null): void
	{
		// We will use the reminder view that was given to the broker to display the
		// password reminder e-mail. We'll pass a "token" variable into the views
		// so that it may be displayed for an user to click for password reset.
		$view = $this->reminderView;

		$this->mailer->send($view, compact('token', 'user'), function($m) use ($user, $token, $callback)
		{
			$m->to($user->getReminderEmail());

			if ($callback !== null) {
                call_user_func($callback, $m, $user, $token);
            }
		});
	}

	/**
	 * Reset the password for the given token.
	 *
	 * @param  array     $credentials
	 * @param  \Closure  $callback
	 * @return RemindableInterface|string|int
     */
	public function reset(array $credentials, Closure $callback): RemindableInterface|string|int
    {
		// If the responses from the validate method is not a user instance, we will
		// assume that it is a redirect and simply return it from this method and
		// the user is properly redirected having an error message on the post.
		$user = $this->validateReset($credentials);

		if ( ! $user instanceof RemindableInterface)
		{
			return $user;
		}

		$pass = $credentials['password'];

		// Once we have called this callback, we will remove this token row from the
		// table and return the response from this callback so the user gets sent
		// to the destination given by the developers from the callback return.
		call_user_func($callback, $user, $pass);

		$this->reminders->delete($credentials['token']);

		return self::PASSWORD_RESET;
	}

    /**
     * Validate a password reset for the given credentials.
     *
     * @param array $credentials
     * @return RemindableInterface|int|string
     */
	protected function validateReset(array $credentials): RemindableInterface|int|string
    {
        $user = $this->getUser($credentials);
        if ($user === null)
		{
			return self::INVALID_USER;
		}

		if (!$this->validNewPasswords($credentials))
		{
			return self::INVALID_PASSWORD;
		}

		if (!$this->reminders->exists($user, $credentials['token']))
		{
			return self::INVALID_TOKEN;
		}

		return $user;
	}

	/**
	 * Set a custom password validator.
	 *
	 * @param  \Closure  $callback
	 * @return void
	 */
	public function validator(Closure $callback): void
    {
		$this->passwordValidator = $callback;
	}

	/**
	 * Determine if the passwords match for the request.
	 *
	 * @param  array  $credentials
	 * @return bool
	 */
	protected function validNewPasswords(array $credentials): bool
    {
		list($password, $confirm) = [$credentials['password'], $credentials['password_confirmation']];

		if (isset($this->passwordValidator))
		{
			return call_user_func($this->passwordValidator, $credentials) && $password === $confirm;
		}

		return $this->validatePasswordWithDefaults($credentials);
	}

	/**
	 * Determine if the passwords are valid for the request.
	 *
	 * @param  array  $credentials
	 * @return bool
	 */
	protected function validatePasswordWithDefaults(array $credentials): bool
    {
		list($password, $confirm) = [$credentials['password'], $credentials['password_confirmation']];

		return $password === $confirm && mb_strlen((string) $password) >= 6;
	}

    /**
     * Get the user for the given credentials.
     *
     * @param array $credentials
     * @return RemindableInterface|null
     * @throws \UnexpectedValueException
     */
	public function getUser(array $credentials): ?RemindableInterface
    {
		$credentials = array_except($credentials, ['token']);

		$user = $this->users->retrieveByCredentials($credentials);

		if ($user && ! $user instanceof RemindableInterface)
		{
			throw new \UnexpectedValueException("User must implement Remindable interface.");
		}

		return $user;
	}

	/**
	 * Get the password reminder repository implementation.
	 *
	 * @return \Illuminate\Auth\Reminders\ReminderRepositoryInterface
	 */
	protected function getRepository(): ReminderRepositoryInterface
    {
		return $this->reminders;
	}

}
