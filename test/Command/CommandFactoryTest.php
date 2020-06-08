<?php
declare (strict_types = 1);

namespace Conductor\Test\Command;

use Command\CommandFactoryTest\TestBadCommand;
use Command\CommandFactoryTest\TestGoodCommand;
use Conductor\Command\CommandConfig;
use Conductor\Command\CommandFactory;
use PHPUnit\Framework\TestCase;
use TypeError;

class CommandFactoryTest extends TestCase
{
    /**
     * @var CommandFactory $factory
     */
    private $factory;

    /**
     * Create a new CommandFactory instance for each test.
     */
    protected function setUp()
    {
        $this->factory = new CommandFactory();
    }

    //==========
    // @create()
    //==========
    /**
     * Test that the create() method runs successfully down the happy path.
     */
    public function testCreateShouldWork()
    {
        $this->factory->create(TestGoodCommand::class);
    }

    /**
     * Test that the create() method successfully injects constructor objects.
     */
    public function testCreateShouldInjectDependencies()
    {
        /**
         * @var TestGoodCommand $instance
         */
        $instance = $this->factory->create(TestGoodCommand::class);

        $this->isInstanceOf(CommandConfig::class)->evaluate($instance);
    }

    public function testCreateShouldRequireCommandBaseClass()
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageRegExp("/Command must inherit from/");

        $this->factory->create(TestBadCommand::class);
    }
}
