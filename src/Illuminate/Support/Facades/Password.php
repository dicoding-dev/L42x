<?php namespace Illuminate\Support\Facades;

/**
 * @see \Illuminate\Auth\Reminders\PasswordBroker
 */
class Password extends Facade {

	/**
	 * Constant representing a successfully sent reminder.
	 */
	final const string REMINDER_SENT = 'reminders.sent';

	/**
	 * Constant representing a successfully reset password.
	 */
	final const string PASSWORD_RESET = 'reminders.reset';

	/**
	 * Constant representing the user not found response.
	 */
	final const string INVALID_USER = 'reminders.user';

	/**
	 * Constant representing an invalid password.
	 */
	final const string INVALID_PASSWORD = 'reminders.password';

	/**
	 * Constant representing an invalid token.
	 */
	final const string INVALID_TOKEN = 'reminders.token';

	/**
	 * Get the registered name of the component.
	 *
	 * @return string
	 */
	protected static function getFacadeAccessor() { return 'auth.reminder'; }

}
