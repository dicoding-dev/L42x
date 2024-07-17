<?php

use Illuminate\Log\Writer;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Queue\QueueManager;
use Illuminate\View\Factory;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
use Symfony\Component\Mailer\SentMessage;

class MailMailerTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }

    public function testMailerSendSendsMessageWithProperViewContent()
    {
        unset($_SERVER['__mailer.test']);

        $view = m::mock(Factory::class);
        $view->shouldReceive('make')->once()->andReturn($view);
        $view->shouldReceive('render')->once()->andReturn('rendered.view');

        $mailer = new Mailer($view, $transport = new ArrayTransport());
        $mailer->send('foo', ['data'], function (Message $message) {
            $message->to('taylor@laravel.com')->from('hello@laravel.com');
        });

        $sentMessages = $transport->messages();
        self::assertCount(1, $sentMessages);

        /** @var SentMessage $sentMessage */
        $sentMessage = $sentMessages[0];
        self::assertStringContainsString('rendered.view', $sentMessage->toString());
        self::assertStringContainsString('Content-Type: text/html;', $sentMessage->toString());
        self::assertEquals('taylor@laravel.com', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        self::assertEquals('hello@laravel.com', $sentMessage->getEnvelope()->getSender()->getAddress());
	}


	public function testMailerSendSendsMessageWithProperPlainViewContent()
	{
        $view = m::mock(Factory::class);
        $view->shouldReceive('make')->twice()->andReturn($view);
        $view->shouldReceive('render')->once()->andReturn('rendered.view');
        $view->shouldReceive('render')->once()->andReturn('rendered.plain');

        $mailer = new Mailer($view, $transport = new ArrayTransport());
        $mailer->send(['foo', 'bar'], ['data'], function (Message $message) {
            $message->to('taylor@laravel.com')->from('hello@laravel.com');
        });

        $sentMessages = $transport->messages();
        self::assertCount(1, $sentMessages);

        /** @var SentMessage $sentMessage */
        $sentMessage = $sentMessages[0];
        $expected = <<<Text
        Content-Type: text/html; charset=utf-8\r
        Content-Transfer-Encoding: quoted-printable\r
        \r
        rendered.view
        Text;

        self::assertStringContainsString($expected, $sentMessage->toString());

        $expected = <<<Text
        Content-Type: text/plain; charset=utf-8\r
        Content-Transfer-Encoding: quoted-printable\r
        \r
        rendered.plain
        Text;

        self::assertStringContainsString($expected, $sentMessage->toString());
        self::assertStringContainsString('Content-Type: text/html;', $sentMessage->toString());
        self::assertEquals('taylor@laravel.com', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        self::assertEquals('hello@laravel.com', $sentMessage->getEnvelope()->getSender()->getAddress());
	}


	public function testMailerSendSendsMessageWithProperPlainViewContentWhenExplicit()
	{
        $view = m::mock(Factory::class);
        $view->shouldReceive('make')->twice()->andReturn($view);
        $view->shouldReceive('render')->once()->andReturn('rendered.view');
        $view->shouldReceive('render')->once()->andReturn('rendered.plain');

        $mailer = new Mailer($view, $transport = new ArrayTransport());
        $mailer->send(['html' => 'foo', 'text' => 'bar'], ['data'], function (Message $message) {
            $message->to('taylor@laravel.com')->from('hello@laravel.com');
        });

        $sentMessages = $transport->messages();
        self::assertCount(1, $sentMessages);

        /** @var SentMessage $sentMessage */
        $sentMessage = $sentMessages[0];
        $expected = <<<Text
        Content-Type: text/html; charset=utf-8\r
        Content-Transfer-Encoding: quoted-printable\r
        \r
        rendered.view
        Text;

        self::assertStringContainsString($expected, $sentMessage->toString());

        $expected = <<<Text
        Content-Type: text/plain; charset=utf-8\r
        Content-Transfer-Encoding: quoted-printable\r
        \r
        rendered.plain
        Text;

        self::assertStringContainsString($expected, $sentMessage->toString());
	}


	public function testMailerCanQueueMessagesToItself()
	{
        $view = m::mock(Factory::class);
        $mailer = new Mailer($view, new ArrayTransport());
		$mailer->setQueue($queue = m::mock(QueueManager::class));
		$queue->shouldReceive('push')->once()->with('mailer@handleQueuedMessage', ['view' => 'foo', 'data' => [1], 'callback' => 'callable'], null);

		$mailer->queue('foo', [1], 'callable');
	}


	public function testMailerCanQueueMessagesToItselfOnAnotherQueue()
	{
        $view = m::mock(Factory::class);
        $mailer = new Mailer($view, new ArrayTransport());
		$mailer->setQueue($queue = m::mock(QueueManager::class));
		$queue->shouldReceive('push')->once()->with('mailer@handleQueuedMessage', ['view' => 'foo', 'data' => [1], 'callback' => 'callable'], 'queue');

		$mailer->queueOn('queue', 'foo', [1], 'callable');
	}


	public function testMailerCanQueueMessagesToItselfWithSerializedClosures()
	{
        $view = m::mock(Factory::class);
        $mailer = new Mailer($view, new ArrayTransport());
		$mailer->setQueue($queue = m::mock(QueueManager::class));
		$serialized = serialize(new Illuminate\Support\SerializableClosure($closure = function() {}));
		$queue->shouldReceive('push')->once()->with('mailer@handleQueuedMessage', ['view' => 'foo', 'data' => [1], 'callback' => $serialized], null);

		$mailer->queue('foo', [1], $closure);
	}


	public function testMailerCanQueueMessagesToItselfLater()
	{
        $view = m::mock(Factory::class);
        $mailer = new Mailer($view, new ArrayTransport());
		$mailer->setQueue($queue = m::mock(QueueManager::class));
		$queue->shouldReceive('later')->once()->with(10, 'mailer@handleQueuedMessage', ['view' => 'foo', 'data' => [1], 'callback' => 'callable'], null);

		$mailer->later(10, 'foo', [1], 'callable');
	}


	public function testMailerCanQueueMessagesToItselfLaterOnAnotherQueue()
	{
        $view = m::mock(Factory::class);
        $mailer = new Mailer($view, new ArrayTransport());
		$mailer->setQueue($queue = m::mock(QueueManager::class));
		$queue->shouldReceive('later')->once()->with(10, 'mailer@handleQueuedMessage', ['view' => 'foo', 'data' => [1], 'callback' => 'callable'], 'queue');

		$mailer->laterOn('queue', 10, 'foo', [1], 'callable');
	}


	public function testMessagesCanBeLoggedInsteadOfSent()
	{
        $view = m::mock(Factory::class);
        $view->shouldReceive('make')->once()->andReturn($view);
        $view->shouldReceive('render')->once()->andReturn('rendered.view');

        $mailer = new Mailer($view, $transport = new ArrayTransport());
        $logger = m::mock(Writer::class);
        $logger->shouldReceive('info')->once()->with('Pretending to mail message to: taylor@userscape.com');
        $mailer->setLogger($logger);
        $mailer->pretend();

        $mailer->send('foo', ['data'], function (Message $message) {
            $message->from('hello@laravel.com');
            $message->to('taylor@userscape.com');
        });

        self::assertEmpty($transport->messages());

//		$mailer = $this->getMock(Mailer::class, ['createMessage'], $this->getMocks());
//		$message = m::mock('StdClass');
//		$mailer->expects($this->once())->method('createMessage')->willReturn($message);
//		$view = m::mock('StdClass');
//		$mailer->getViewFactory()->shouldReceive('make')->once()->with('foo', ['data', 'message' => $message])->andReturn($view);
//		$view->shouldReceive('render')->once()->andReturn('rendered.view');
//		$message->shouldReceive('setBody')->once()->with('rendered.view', 'text/html');
//		$message->shouldReceive('setFrom')->never();
//		$mailer->setSwiftMailer(m::mock('StdClass'));
//		$message->shouldReceive('getTo')->once()->andReturn(['taylor@userscape.com' => 'Taylor']);
//		$message->shouldReceive('getSwiftMessage')->once()->andReturn($message);
//		$mailer->getSwiftMailer()->shouldReceive('send')->never();
//		$logger = m::mock(Writer::class);
//		$logger->shouldReceive('info')->once()->with('Pretending to mail message to: taylor@userscape.com');
//		$mailer->setLogger($logger);
//		$mailer->pretend();
//
//		$mailer->send('foo', ['data'], function($m) {});
	}


	public function testMailerCanResolveMailerClasses()
	{
        $view = m::mock(Factory::class);
        $view->shouldReceive('make')->once()->andReturn($view);
        $view->shouldReceive('render')->once()->andReturn('rendered.view');

        $mailer = new Mailer($view, $transport = new ArrayTransport());
        $container = new Illuminate\Container\Container();
        $mailer->setContainer($container);
        $fooMailer = new class {
            public int $calledTimes = 0;
            public function mail(Message $message): void
            {
                $message->from('hello@laravel.com');
                $message->to('taylor@laravel.com');
                $this->calledTimes++;
            }
        };
        $container['FooMailer'] = $container->share(fn() => $fooMailer);

		$mailer->send('foo', ['data'], 'FooMailer');

        $sentMessage = $transport->messages()[0];
        self::assertEquals(1, $fooMailer->calledTimes);
        self::assertEquals('taylor@laravel.com', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        self::assertEquals('hello@laravel.com', $sentMessage->getEnvelope()->getSender()->getAddress());

	}


	public function testGlobalFromIsRespectedOnAllMessages()
	{
        $view = m::mock(Factory::class);
        $view->shouldReceive('make')->once()->andReturn($view);
        $view->shouldReceive('render')->once()->andReturn('rendered.view');
        $mailer = new Mailer($view, $transport = new ArrayTransport);
        $mailer->alwaysFrom('hello@laravel.com');

        $mailer->send('foo', ['data'], function (Message $message) {
            $message->to('taylor@laravel.com');
        });


        $sentMessages = $transport->messages();
        self::assertCount(1, $sentMessages);

        /** @var SentMessage $sentMessage */
        $sentMessage = $sentMessages[0];
        self::assertSame('taylor@laravel.com', $sentMessage->getEnvelope()->getRecipients()[0]->getAddress());
        self::assertSame('hello@laravel.com', $sentMessage->getEnvelope()->getSender()->getAddress());
	}


	protected function getMailer()
	{
		return new Illuminate\Mail\Mailer(m::mock(Factory::class), m::mock('Swift_Mailer'));
	}


	protected function getMocks()
	{
		return [m::mock(Factory::class), m::mock('Swift_Mailer')];
	}

}

class FailingSwiftMailerStub
{
	public function send($message, &$failed)
	{
		$failed[] = 'taylorotwell@gmail.com';
	}
}
