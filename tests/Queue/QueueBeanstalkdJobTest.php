<?php

use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\BeanstalkdJob;
use L4\Tests\BackwardCompatibleTestCase;
use Pheanstalk\Contract\PheanstalkInterface;
use Pheanstalk\Job;
use Pheanstalk\Pheanstalk;
use Prophecy\Prophecy\ObjectProphecy;

class QueueBeanstalkdJobTest extends BackwardCompatibleTestCase
{
    /**
     * @var Container|ObjectProphecy
     */
    private $container;
    /**
     * @var Pheanstalk|ObjectProphecy
     */
    private $pheanstalk;
    /**
     * @var Job|ObjectProphecy
     */
    private $pheanstalkJob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->prophesize(Container::class);
        $this->pheanstalk = $this->prophesize(Pheanstalk::class);
        $this->pheanstalkJob = $this->prophesize(Job::class);
    }

    public function testFireProperlyCallsTheJobHandler(): void
    {
        $this->pheanstalkJob->getData()->willReturn(json_encode(['job' => 'foo', 'data' => ['data1']]));

        $handler = $this->prophesize(BeanstalkdDummyHandler::class);
        $this->container->make('foo')->willReturn($handler->reveal());

        $job = $this->getProphesizedJob();

        $handler->fire($job, ['data1'])->shouldBeCalledOnce();

		$job->fire();
	}

	public function testDeleteRemovesTheJobFromBeanstalkd(): void
    {
        $this->pheanstalk->delete($this->pheanstalkJob)
            ->shouldBeCalledOnce();

        $job = $this->getProphesizedJob();

		$job->delete();
	}

	public function testReleaseProperlyReleasesJobOntoBeanstalkd(): void
    {
        $this->pheanstalk->release(
            $this->pheanstalkJob,
            PheanstalkInterface::DEFAULT_PRIORITY,
            0
        )->shouldBeCalledOnce();

        $job = $this->getProphesizedJob();

        $job->release();
    }

	public function testBuryProperlyBuryTheJobFromBeanstalkd(): void
    {
        $this->pheanstalk->bury($this->pheanstalkJob)->shouldBeCalledOnce();

        $job = $this->getProphesizedJob();

		$job->bury();
	}

    protected function getProphesizedJob(): BeanstalkdJob
    {
        return new BeanstalkdJob(
            $this->container->reveal(),
            $this->pheanstalk->reveal(),
            $this->pheanstalkJob->reveal(),
            'default'
        );
    }
}

class BeanstalkdDummyHandler
{
    public function fire(\Illuminate\Queue\Jobs\Job $job, $data): void
    {

    }
}