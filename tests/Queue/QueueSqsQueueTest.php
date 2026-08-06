<?php

use Aws\Result;
use Aws\Sqs\SqsClient;
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\SqsJob;
use Illuminate\Queue\SqsQueue;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class QueueSqsQueueTest extends BackwardCompatibleTestCase
{

    private $sqs;
    private string $account;
    private string $queueName;
    private string $baseUrl;
    private string $prefix;
    private string $queueUrl;
    private string $mockedJob;
    private array $mockedData;
    private string|false $mockedPayload;
    private int $mockedDelay;
    private string $mockedMessageId;
    private string $mockedReceiptHandle;
    private Result $mockedSendMessageResponseModel;
    private Result $mockedReceiveMessageResponseModel;

    protected function tearDown(): void
    {
        m::close();
    }

    protected function setUp(): void
    {
        $this->sqs = m::mock(SqsClient::class);

        $this->account = '1234567891011';
        $this->queueName = 'emails';
        $this->baseUrl = 'https://sqs.someregion.amazonaws.com';

        // This is how the modified getQueue builds the queueUrl.
        $this->prefix = $this->baseUrl . '/' . $this->account . '/';
        $this->queueUrl = $this->prefix . $this->queueName;

        $this->mockedJob = 'foo';
        $this->mockedData = ['data'];
        $this->mockedPayload = json_encode(['job' => $this->mockedJob, 'data' => $this->mockedData]);
        $this->mockedDelay = 10;
        $this->mockedMessageId = 'e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81';
        $this->mockedReceiptHandle = '0NNAq8PwvXuWv5gMtS9DJ8qEdyiUwbAjpp45w2m6M4SJ1Y+PxCh7R930NRB8ylSacEmoSnW18bgd4nK/O6ctE';

        $this->mockedSendMessageResponseModel = new Result([
            'Body' => $this->mockedPayload,
            'MD5OfBody' => md5((string) $this->mockedPayload),
            'ReceiptHandle' => $this->mockedReceiptHandle,
            'MessageId' => $this->mockedMessageId,
            'Attributes' => ['ApproximateReceiveCount' => 1],
        ]);

        $this->mockedReceiveMessageResponseModel = new Result([
            'Messages' => [
                0 => [
                    'Body' => $this->mockedPayload,
                    'MD5OfBody' => md5((string) $this->mockedPayload),
                    'ReceiptHandle' => $this->mockedReceiptHandle,
                    'MessageId' => $this->mockedMessageId,
                ],
            ],
        ]);
    }

    public function testPopProperlyPopsJobOffOfSqs()
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer(m::mock(Container::class));
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('receiveMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'AttributeNames' => ['ApproximateReceiveCount']])->andReturn($this->mockedReceiveMessageResponseModel);
        $result = $queue->pop($this->queueName);
        $this->assertInstanceOf(SqsJob::class, $result);
    }

    public function testDelayedPushWithDateTimeProperlyPushesJobOntoSqs()
    {
        $now = Carbon::now();
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getSeconds', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getSeconds')->with($now)->willReturn(5);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload, 'DelaySeconds' => 5])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->later($now, $this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
    }

    public function testDelayedPushProperlyPushesJobOntoSqs()
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getSeconds', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getSeconds')->with($this->mockedDelay)->willReturn($this->mockedDelay);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload, 'DelaySeconds' => $this->mockedDelay])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->later($this->mockedDelay, $this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
    }

    public function testPushProperlyPushesJobOntoSqs()
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
    }

    public function testGetQueueProperlyResolvesUrlWithPrefix()
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $this->assertEquals($this->queueUrl, $queue->getQueue(null));
        $this->assertEquals($this->baseUrl . '/' . $this->account . '/test', $queue->getQueue('test'));
    }

    public function testGetQueueProperlyResolvesUrlWithoutPrefix()
    {
        $queue = new SqsQueue($this->sqs, $this->queueUrl);
        $this->assertEquals($this->queueUrl, $queue->getQueue(null));
        $this->assertEquals($this->queueUrl, $queue->getQueue($this->queueUrl));
    }

}
