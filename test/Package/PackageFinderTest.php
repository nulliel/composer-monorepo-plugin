<?php
namespace Monorepo\Test\Package;
/*
use Monorepo\Package\PackageFinder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PackageFinderTest extends TestCase
{
    //=============
    // @getPackages
    //=============
    public function testGetPackagesReturnsCorrectFiles()
    {
        $packages = [
            "projectA" => "",
            "projectB" => "",
            "vendor/test/one" => "",
            "vendor/test/two" => ""
        ];

        $finder = new PackageFinder();
        $finder
            ->from(__DIR__ . "/PackageFinderTest")
            ->packageFile("monorepo.json")
            ->vendorDirectory("vendor");

        $packageList = $finder->getPackages();

        foreach ($packageList as $fileName => $fileValue) {
            $this->assertArrayHasKey($fileName, $packages);
            $this->addToAssertionCount(1);
        }

        $this->assertEquals(4, $this->getNumAssertions());
    }

    //==================
    // @getLocalPackages
    //==================
    public function testGetLocalPackagesReturnsCorrectFiles()
    {
        $packages = [
            "projectA" => "",
            "projectB" => ""
        ];

        $finder = new PackageFinder();
        $finder
            ->from(__DIR__ . "/PackageFinderTest")
            ->packageFile("monorepo.json")
            ->vendorDirectory("vendor")
            ->excludeVendor();

        foreach ($finder as $fileName => $fileValue) {
            $this->assertArrayHasKey($fileName, $packages);
            $this->addToAssertionCount(1);
        }

        $this->assertEquals(2, $this->getNumAssertions());
    }

    public function testGetLocalPackagesReturnsNothingWhenExcluded()
    {
        $packages = [];

        $finder = new PackageFinder();
        $finder
            ->from(__DIR__ . "/PackageFinderTest")
            ->packageFile("monorepo.json")
            ->vendorDirectory("vendor")
            ->excludeLocal()
            ->excludeVendor();

        foreach ($finder as $fileName => $fileValue) {
            $packages[$fileName] = $fileValue;
        }

        $this->assertCount(0, $packages);
    }

    //===================
    // @getVendorPackages
    //===================
    public function testGetVendorPackagesReturnsCorrectFiles()
    {
        $packages = [
            "vendor/test/one" => "",
            "vendor/test/two" => ""
        ];

        $finder = new PackageFinder();
        $finder
            ->from(__DIR__ . "/PackageFinderTest")
            ->vendorDirectory("vendor")
            ->excludeLocal();

        foreach ($finder as $fileName => $fileValue) {
            $this->assertArrayHasKey($fileName, $packages);
            $this->addToAssertionCount(1);
        }


        $this->assertEquals(2, $this->getNumAssertions());
    }
    
    public function testGetVendorPackagesReturnsNothingWhenExcluded()
    {
        $packages = [];

        $finder = new PackageFinder();
        $finder
            ->from(__DIR__ . "/PackageFinderTest")
            ->packageFile("monorepo.json")
            ->vendorDirectory("/vendor")
            ->excludeLocal()
            ->excludeVendor();

        foreach ($finder as $fileName => $fileValue) {
            $packages[$fileName] = $fileValue;
        }

        $this->assertCount(0, $packages);
    }

    //==================
    // @validateIterator
    //==================
    public function testValidateIteratorFailsWhenMissingFrom()
    {
        $finder = new PackageFinder();
        $finder
            ->vendorDirectory(".");

        $this->setExpectedException(RuntimeException::class, "from must be given");
        $finder->getIterator();
    }

    public function testValidateIteratorFailsWhenMissingVendorDirectory()
    {
        $finder = new PackageFinder();
        $finder
            ->from(".");

        $this->setExpectedException(RuntimeException::class, "vendorDirectory must be given");
        $finder->getIterator();
    }

    public function testValidateIteratorFailsWhenMissingPackageFile()
    {
        $finder = new PackageFinder();
        $finder
            ->from(".")
            ->vendorDirectory(".");

        $this->setExpectedException(RuntimeException::class, "packageFile must be given if finding local packages");
        $finder->getIterator();
    }

    public function testValidateIteratorWorks()
    {
        $finder = new PackageFinder();
        $finder
            ->from(".")
            ->vendorDirectory(".")
            ->packageFile(".");

        $this->assertNotNull($finder->getIterator());
    }

    //=======
    // @count
    //=======
    public function testCountReturnsTheCorrectValue()
    {
        $finder = new PackageFinder();
        $finder
            ->from(__DIR__ . "/PackageFinderTest")
            ->packageFile("monorepo.json")
            ->vendorDirectory("vendor");

        $this->assertEquals(4, $finder->count());
    }
}
*/
