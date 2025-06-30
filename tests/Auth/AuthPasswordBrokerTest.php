<?php /** @noinspection PhpParamsInspection */

use Illuminate\Auth\Reminders\PasswordBroker;
use Illuminate\Auth\Reminders\RemindableInterface;
use Illuminate\Auth\Reminders\ReminderRepositoryInterface;
use Illuminate\Auth\UserProviderInterface;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\View\Factory;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Mailer\SentMessage;

class AuthPasswordBrokerTest extends BackwardCompatibleTestCase
{

    /**
     * @var ReminderRepositoryInterface|ObjectProphecy
     */
    private $reminderRepository;
    /**
     * @var UserProviderInterface|ObjectProphecy
     */
    private $userProvider;
    /**
     * @var Mailer|ObjectProphecy
     */
    private $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reminderRepository = $this->prophesize(ReminderRepositoryInterface::class);
        $this->userProvider = $this->prophesize(UserProviderInterface::class);
        $this->mailer = $this->prophesize(Mailer::class);
    }

    protected function tearDown(): void
    {
        m::close();
    }


	public function testIfUserIsNotFoundErrorRedirectIsReturned()
	{
		$broker = new PasswordBroker(
            $this->reminderRepository->reveal(),
            $this->userProvider->reveal(),
            $this->mailer->reveal(),
            'reminderView'
        );

		$this->userProvider->retrieveByCredentials(['credentials'])
            ->willReturn(null);

		$this->assertEquals(PasswordBroker::INVALID_USER, $broker->remind(['credentials']));
	}


    public function testGetUserThrowsExceptionIfUserDoesntImplementRemindable()
    {
        $this->expectException(UnexpectedValueException::class);
        $broker = $this->getBroker($mocks = $this->getMocks());
        $mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn('bar');

        $broker->getUser(['foo']);
    }


	public function testUserIsRetrievedByCredentials()
	{
		$broker = $this->getBroker($mocks = $this->getMocks());
		$mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(
            RemindableInterface::class
        ));

		$this->assertEquals($user, $broker->getUser(['foo']));
	}


	public function testBrokerCreatesReminderAndRedirectsWithoutError()
	{
		unset($_SERVER['__reminder.test']);
		$mocks = $this->getMocks();
		$broker = $this->getMock(PasswordBroker::class, ['sendReminder'], array_values($mocks));
		$mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user = m::mock(
            RemindableInterface::class
        ));
		$mocks['reminders']->shouldReceive('create')->once()->with($user)->andReturn('token');
		$callback = function() {};
		$broker->expects($this->once())->method('sendReminder')->with($this->equalTo($user), $this->equalTo('token'), $this->equalTo($callback));

		$this->assertEquals(PasswordBroker::REMINDER_SENT, $broker->remind(['foo'], $callback));
	}


	public function testMailerIsCalledWithProperViewTokenAndCallback()
	{
        $factoryView = m::mock(Factory::class);
        $factoryView->shouldReceive('make')->once()->andReturnUsing(function ($view, $data) use($factoryView, &$maker) {
            $factoryView->shouldReceive('render')->once()->andReturn($view);
            return $factoryView;
        });

        $broker = $this->getBroker($mocks = [
            ...$this->getMocks(),
            'mailer' => new Mailer($factoryView, $transport = new ArrayTransport())
        ]);
        $mocks['mailer']->alwaysFrom('sender@mail.com');

        $user = m::mock(RemindableInterface::class);
        $user->shouldReceive('getReminderEmail')->once()->andReturn('user@email.com');

        $receivedCallback = new stdClass();
        $someCallback = function($message, $user, $token) use (&$receivedCallback) {
            $receivedCallback->user = $user;
            $receivedCallback->token = $token;
        };

        $broker->sendReminder($user, 'token', $someCallback);

        /** @var SentMessage $message */
        $message = $transport->messages()[0];
        self::assertEquals('user@email.com', $message->getEnvelope()->getRecipients()[0]->getAddress());
        self::assertStringContainsString('reminderView', $message->toString());
        self::assertEquals($user, $receivedCallback->user);
        self::assertEquals('token', $receivedCallback->token);
	}


	public function testRedirectIsReturnedByResetWhenUserCredentialsInvalid()
	{
		$broker = $this->getBroker($mocks = $this->getMocks());
		$mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(['creds'])->andReturn(null);

		$this->assertEquals(PasswordBroker::INVALID_USER, $broker->reset(['creds'], function() {}));
	}


	public function testRedirectReturnedByRemindWhenPasswordsDontMatch()
	{
		$creds = ['password' => 'foo', 'password_confirmation' => 'bar'];
		$broker = $this->getBroker($mocks = $this->getMocks());
		$mocks['users']->shouldReceive('retrieveByCredentials')->once()->with($creds)->andReturn($user = m::mock(
            RemindableInterface::class
        ));

		$this->assertEquals(PasswordBroker::INVALID_PASSWORD, $broker->reset($creds, function() {}));
	}


	public function testRedirectReturnedByRemindWhenPasswordNotSet()
	{
		$creds = ['password' => null, 'password_confirmation' => null];
		$broker = $this->getBroker($mocks = $this->getMocks());
		$mocks['users']->shouldReceive('retrieveByCredentials')->once()->with($creds)->andReturn($user = m::mock(
            RemindableInterface::class
        ));

		$this->assertEquals(PasswordBroker::INVALID_PASSWORD, $broker->reset($creds, function() {}));
	}


	public function testRedirectReturnedByRemindWhenPasswordsLessThanSixCharacters()
	{
		$creds = ['password' => 'abc', 'password_confirmation' => 'abc'];
		$broker = $this->getBroker($mocks = $this->getMocks());
		$mocks['users']->shouldReceive('retrieveByCredentials')->once()->with($creds)->andReturn($user = m::mock(
            RemindableInterface::class
        ));

		$this->assertEquals(PasswordBroker::INVALID_PASSWORD, $broker->reset($creds, function() {}));
	}


	public function testRedirectReturnedByRemindWhenPasswordDoesntPassValidator()
	{
		$creds = ['password' => 'abcdef', 'password_confirmation' => 'abcdef'];
		$broker = $this->getBroker($mocks = $this->getMocks());
		$broker->validator(function($credentials) { return strlen((string) $credentials['password']) >= 7; });
		$mocks['users']->shouldReceive('retrieveByCredentials')->once()->with($creds)->andReturn($user = m::mock(
            RemindableInterface::class
        ));

		$this->assertEquals(PasswordBroker::INVALID_PASSWORD, $broker->reset($creds, function() {}));
	}


	public function testRedirectReturnedByRemindWhenRecordDoesntExistInTable()
	{
		$creds = ['token' => 'token'];
		$broker = $this->getMock(PasswordBroker::class, ['validNewPasswords'], array_values($mocks = $this->getMocks()));
		$mocks['users']->shouldReceive('retrieveByCredentials')->once()->with(array_except($creds, ['token']))->andReturn($user = m::mock(
            RemindableInterface::class
        ));
		$broker->expects($this->once())->method('validNewPasswords')->willReturn(true);
		$mocks['reminders']->shouldReceive('exists')->with($user, 'token')->andReturn(false);

		$this->assertEquals(PasswordBroker::INVALID_TOKEN, $broker->reset($creds, function() {}));
	}


	public function testResetRemovesRecordOnReminderTableAndCallsCallback()
	{
		unset($_SERVER['__auth.reminder']);
		$broker = $this->getMock(PasswordBroker::class, ['validateReset'], array_values($mocks = $this->getMocks()));
		$broker->expects($this->once())->method('validateReset')->willReturn(
            $user = m::mock(
                RemindableInterface::class
            )
        );
		$mocks['reminders']->shouldReceive('delete')->once()->with('token');
		$callback = function($user, $password)
		{
			$_SERVER['__auth.reminder'] = compact('user', 'password');
			return 'foo';
		};

		$this->assertEquals(PasswordBroker::PASSWORD_RESET, $broker->reset(
            ['password' => 'password', 'token' => 'token'], $callback));
		$this->assertEquals(['user' => $user, 'password' => 'password'], $_SERVER['__auth.reminder']);
	}


	protected function getBroker($mocks): PasswordBroker
    {
		return new PasswordBroker($mocks['reminders'], $mocks['users'], $mocks['mailer'], $mocks['view']);
	}


	protected function getMocks(): array
    {
        return [
            'reminders' => m::mock(ReminderRepositoryInterface::class),
            'users'     => m::mock(UserProviderInterface::class),
            'mailer'    => m::mock(Mailer::class),
            'view'      => 'reminderView',
        ];
	}

}
