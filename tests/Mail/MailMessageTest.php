<?php

use Illuminate\Mail\Message;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
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
