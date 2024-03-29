<?php

use Illuminate\Support\SerializableClosure as S;
use L4\Tests\BackwardCompatibleTestCase;

class SupportSerializableClosureTest extends BackwardCompatibleTestCase {

	public function testClosureCanBeSerializedAndRebuilt(): void
    {
		$f = new S(function() { return 'hello'; });
		$serialized = serialize($f);
		$unserialized = unserialize($serialized);

		/** @var \Closure $unserialized */
		$this->assertEquals('hello', $unserialized());
	}


	public function testClosureCanBeSerializedAndRebuiltAndInheritState(): void
    {
		$a = 1;
		$b = 1;
		$f = new S(function($i) use ($a, $b)
		{
			return $a + $b + $i;
		});
		$serialized = serialize($f);
		$unserialized = unserialize($serialized);

		/** @var \Closure $unserialized */
		$this->assertEquals(3, $unserialized(1));
	}


//	public function testCanGetCodeAndVariablesFromObject()
//	{
//		$a = 1;
//		$b = 2;
//		$f = new S(function($i) use ($a, $b)
//		{
//			return $a + $b + $i;
//		});
//
//		$expectedVars = array('a' => 1, 'b' => 2);
//		$expectedCode = 'function ($i) use($a, $b) {
//    return $a + $b + $i;
//};';
//		$this->assertEquals($expectedVars, $f->getVariables());
//		$this->assertEquals($expectedCode, $f->getCode());
//	}

}
