<?php

use Illuminate\Queue\SqsQueue;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class QueueSqsQueueGetQueueTest extends BackwardCompatibleTestCase
{

    private const PREFIX = 'https://sqs.us-east-1.amazonaws.com/1234567890';

    protected function tearDown(): void
    {
        m::close();
    }

    /**
     * @dataProvider queueProvider
     */
    public function testGetQueueResolvesTheQueueUrl($prefix, $default, $queue, $expected)
    {
        $sqs = new SqsQueue(m::mock('Aws\Sqs\SqsClient'), $default, $prefix);

        $this->assertSame($expected, $sqs->getQueue($queue));
    }

    public static function queueProvider(): array
    {
        return [
            'bare name is prefixed'      => [self::PREFIX, 'default', 'emails', self::PREFIX . '/emails'],
            'null falls back to default' => [self::PREFIX, 'default', null, self::PREFIX . '/default'],
            'full url passes through'    => [self::PREFIX, 'default', self::PREFIX . '/emails', self::PREFIX . '/emails'],
            'trailing slash is trimmed'  => [self::PREFIX . '/', 'default', 'emails', self::PREFIX . '/emails'],
            'empty prefix leaves name'   => ['', 'default', 'emails', 'emails'],
        ];
    }

}
