<?php

use Illuminate\Foundation\AssetPublisher;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class FoundationAssetPublishCommandTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testCommandCallsPublisherWithProperPackageName()
    {
        $command = new Illuminate\Foundation\Console\AssetPublishCommand(
            $pub = m::mock(AssetPublisher::class)
        );
        $pub->shouldReceive('publishPackage')->once()->with('foo');
        $command->setLaravel(tap(new Illuminate\Foundation\Application, fn($a) => $a->instance('env', 'testing')));
        $command->run(
            new Symfony\Component\Console\Input\ArrayInput(['package' => 'foo']),
            new Symfony\Component\Console\Output\NullOutput
        );
	}

}
