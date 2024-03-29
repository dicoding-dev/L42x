<?php

use Illuminate\Foundation\Artisan;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class FoundationArtisanTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testArtisanIsCalledWithProperArguments(): void
    {
        $artisan = $this->getMock(
            Artisan::class,
            ['getArtisan'],
            [$app = new Illuminate\Foundation\Application]
        );
        $artisan->expects($this->once())->method('getArtisan')->willReturn(
            $console = m::mock('Illuminate\Console\Application[find]')
        );
        $console->shouldReceive('find')->once()->with('foo')->andReturn($command = m::mock('StdClass'));
		$command->shouldReceive('run')->once()->with(m::type(ArrayInput::class), m::type(
            NullOutput::class
        ))->andReturnUsing(function($input, $output)
		{
			return $input;
		});

		$input = $artisan->call('foo', ['--bar' => 'baz']);
		$this->assertEquals('baz', $input->getParameterOption('--bar'));
	}

}
