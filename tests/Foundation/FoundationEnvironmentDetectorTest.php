<?php

use Illuminate\Foundation\EnvironmentDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FoundationEnvironmentDetectorTest extends TestCase
{
	#[Test]
	public function theCallbackNamesTheEnvironment()
	{
		$this->assertSame('foobar', (new EnvironmentDetector)->detect(fn () => 'foobar'));
	}

	#[Test]
	public function anEnvOptionWithAnEqualsSignOverridesTheCallback()
	{
		$this->assertSame('local', (new EnvironmentDetector)->detect(fn () => 'foobar', array('artisan', '--env=local')));
	}

	#[Test]
	public function anEnvOptionFollowedByItsValueOverridesTheCallback()
	{
		$this->assertSame('local', (new EnvironmentDetector)->detect(fn () => 'foobar', array('artisan', 'migrate', '--env', 'local')));
	}

	#[Test]
	public function consoleArgumentsWithoutAnEnvOptionLeaveItToTheCallback()
	{
		$detector = new EnvironmentDetector;

		$this->assertSame('foobar', $detector->detect(fn () => 'foobar', array('artisan', 'migrate', '--force')));
		$this->assertSame('foobar', $detector->detect(fn () => 'foobar', array('artisan', '--env')));
		$this->assertSame('foobar', $detector->detect(fn () => 'foobar', array('artisan', '--environment=local')));
	}
}
