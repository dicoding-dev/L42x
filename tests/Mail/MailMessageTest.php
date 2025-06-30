<?php

use Illuminate\Mail\Message;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailMessageTest extends BackwardCompatibleTestCase
{
    private static string $staticFilePath;

    private Message $message;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        file_put_contents(self::$staticFilePath = __DIR__.'/foo.jpg', 'expected attachment body');
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        @unlink(self::$staticFilePath);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->message = new Message(new Email());
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function testFromMethod()
    {
        self::assertInstanceOf(Message::class, $message = $this->message->from('foo@bar.baz', 'Foo'));
        self::assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getFrom()[0]);
    }

    public function testSenderMethod()
    {
        self::assertInstanceOf(Message::class, $message = $this->message->sender('foo@bar.baz', 'Foo'));
        self::assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getSender());
    }

    public function testReturnPathMethod()
    {
        self::assertInstanceOf(Message::class, $message = $this->message->returnPath('foo@bar.baz'));
        self::assertEquals(new Address('foo@bar.baz'), $message->getSymfonyMessage()->getReturnPath());
    }

    public function testToMethod()
    {
        self::assertInstanceOf(Message::class, $message = $this->message->to('foo@bar.baz', 'Foo'));
        self::assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getTo()[0]);

        self::assertInstanceOf(Message::class, $message = $this->message->to(['bar@bar.baz' => 'Bar']));
        self::assertEquals(new Address('bar@bar.baz', 'Bar'), $message->getSymfonyMessage()->getTo()[0]);
    }

    public function testCcMethod()
    {
        self::assertInstanceOf(Message::class, $message = $this->message->cc('foo@bar.baz', 'Foo'));
        self::assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getCc()[0]);
    }

    public function testBccMethod()
    {
        self::assertInstanceOf(Message::class, $message = $this->message->bcc('foo@bar.baz', 'Foo'));
        self::assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getBcc()[0]);
    }

    public function testReplyToMethod()
    {
        self::assertInstanceOf(Message::class, $message = $this->message->replyTo('foo@bar.baz', 'Foo'));
        self::assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getReplyTo()[0]);
    }

    public function testSubjectMethod()
    {
        self::assertInstanceOf(Message::class, $message = $this->message->subject('foo'));
        self::assertSame('foo', $message->getSymfonyMessage()->getSubject());
    }

    public function testPriorityMethod()
    {
        self::assertInstanceOf(Message::class, $message = $this->message->priority(1));
        self::assertEquals(1, $message->getSymfonyMessage()->getPriority());
    }

    public function testBasicAttachment(): void
    {
        $this->message->attach(self::$staticFilePath, ['as' => 'bar.jpg', 'mime' => 'image/jpg']);

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $headers = $attachment->getPreparedHeaders()->toArray();
        self::assertSame('expected attachment body', $attachment->getBody());
        self::assertSame('Content-Type: image/jpg; name=bar.jpg', $headers[0]);
        self::assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        self::assertSame('Content-Disposition: attachment; name=bar.jpg; filename=bar.jpg', $headers[2]);
	}


	public function testDataAttachment(): void
	{
        $this->message->attachData('expected attachment body', 'foo.jpg', ['mime' => 'image/jpg']);

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $headers = $attachment->getPreparedHeaders()->toArray();
        self::assertSame('expected attachment body', $attachment->getBody());
        self::assertSame('Content-Type: image/jpg; name=foo.jpg', $headers[0]);
        self::assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        self::assertSame('Content-Disposition: attachment; name=foo.jpg; filename=foo.jpg', $headers[2]);
	}

}
