<?php
namespace Config;

/*
use InvalidArgumentException;
use Monorepo\Config\ComposerConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ComposerConfigTest extends TestCase
{
    //====================
    // @getRootDirectory
    // @getVendorDirectory
    //====================
    public function testDefaultComposerConfigsWork()
    {
        $config = new ComposerConfig();

        $this->assertEquals(realpath(__DIR__ . "/../../"), $config->getRootDirectory());
        $this->assertEquals("vendor", $config->getVendorDirectory());
    }

    public function testComposerConfigWithChangedRootDirectory()
    {
        chdir(__DIR__);

        $config = new ComposerConfig(__DIR__ . "/ComposerConfigTest/Default/composer.json");

        $this->assertEquals(__DIR__, $config->getRootDirectory());
        $this->assertEquals("vendor", $config->getVendorDirectory());
    }

    public function testComposerConfigWithChangedVendorDirectory()
    {
        $config = new ComposerConfig(__DIR__ . "/ComposerConfigTest/Vendor/composer.json");

        $this->assertEquals(realpath(__DIR__ . "/../../"), $config->getRootDirectory());
        $this->assertEquals("test", $config->getVendorDirectory());
    }

    //============
    // @loadConfig
    //============
    public function testLoadConfigThrowsWhenConfigFileDoesNotExist()
    {
        $this->setExpectedException(RuntimeException::class, "composer.json not found at " . __DIR__);

        $config = new ComposerConfig(__DIR__ . "/path/doesnt/exist");
    }

    public function testLoadConfigThrowsWhenConfigFileNotFound()
    {
        $this->setExpectedException(InvalidArgumentException::class);

        $config = new ComposerConfig(__DIR__);
    }
}*/
