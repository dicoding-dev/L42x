<?php

namespace L4\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

class BackwardCompatibleTestCase extends TestCase
{
    use ProphecyTrait;

    /**
     * Returns a mock object for the specified class.
     *
     * Backward compatibility for tests still using the old (4.8) getMock() method.
     *
     * @param string $originalClassName Name of the class to mock.
     * @param array|null $methods Methods to replace with a configurable test double; null replaces none.
     * @param array $arguments Parameters to pass to the original class' constructor.
     *
     * @return MockObject
     */
    public function getMock($originalClassName, $methods = array(), array $arguments = array())
    {
        $builder = $this->getMockBuilder($originalClassName)->setConstructorArgs($arguments);

        if (is_array($methods)) {
            $builder->onlyMethods($methods);
        }

        return $builder->getMock();
    }
}