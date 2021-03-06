<?php

use Illuminate\Mail\Message;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class MailMessageTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testBasicAttachment()
    {
        $swift = m::mock('StdClass');
        $message = $this->getMock(Message::class, ['createAttachmentFromPath'], [$swift]);
        $attachment = m::mock('StdClass');
		$message->expects($this->once())->method('createAttachmentFromPath')->with($this->equalTo('foo.jpg'))->willReturn(
            $attachment
        );
		$swift->shouldReceive('attach')->once()->with($attachment);
		$attachment->shouldReceive('setContentType')->once()->with('image/jpeg');
		$attachment->shouldReceive('setFilename')->once()->with('bar.jpg');
		$message->attach('foo.jpg', ['mime' => 'image/jpeg', 'as' => 'bar.jpg']);
	}


	public function testDataAttachment()
	{
		$swift = m::mock('StdClass');
		$message = $this->getMock(Message::class, ['createAttachmentFromData'], [$swift]);
		$attachment = m::mock('StdClass');
		$message->expects($this->once())->method('createAttachmentFromData')->with($this->equalTo('foo'), $this->equalTo('name'))->willReturn(
            $attachment
        );
		$swift->shouldReceive('attach')->once()->with($attachment);
		$attachment->shouldReceive('setContentType')->once()->with('image/jpeg');
		$message->attachData('foo', 'name', ['mime' => 'image/jpeg']);
	}

}
