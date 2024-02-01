<?php
/**
 * Defines the Base_TestCase
 */

namespace test\unit\Ingenerator\ScheduledTaskRunner;


use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BaseTestCase extends TestCase
{
    /**
     * @psalm-template RealInstanceType of object
     * @psalm-param class-string<RealInstanceType> $className
     * @psalm-return MockObject&RealInstanceType
     */
    protected function getDummy(string $class)
    {
        return $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableProxyingToOriginalMethods()
            ->getMock();
    }

    /**
     * @psalm-template RealInstanceType of object
     * @psalm-param class-string<RealInstanceType> $className
     * @psalm-return MockObject&RealInstanceType
     */
    protected function getDummyExpectingNoCalls(string $className)
    {
        $mock = $this->getDummy($className);
        $mock->expects($this->never())->method($this->anything());

        return $mock;
    }
}
