<?php
declare(strict_types = 1);

namespace Conductor\Autoload;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\PackageSorter;
use Conductor\Package\MonorepoPackage;
use Exception;
use Iterator;

class AutoloadGenerator
{
    public function __construct(private IOInterface $io, private bool $devMode) {}

    /**
     * @throws Exception
     */
    public function dump(MonorepoPackage $package): int
    {
        $filesystem = new Filesystem();

        $basePath = $package->composerFile;

        if (!$basePath) {
            return 0;
        }

        $basePath   = $filesystem->normalizePath($basePath->dirname()->getPath());
        $vendorPath = $filesystem->normalizePath(realpath($package->getVendorDirectory()->getPath()));

        $targetDir = "${vendorPath}/composer";

        $filesystem->ensureDirectoryExists($targetDir);
        $filesystem->ensureDirectoryExists($package->getVendorDirectory()->getPath());

        // $vendorPathCode = $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true);
        // $vendorPathCode52 = str_replace('__DIR__', 'dirname(__FILE__)', $vendorPathCode);

        // $appBaseDirCode = $filesystem->findShortestPathCode($vendorPath, $basePath, true);
        // $appBaseDirCode = str_replace('__DIR__', '$vendorDir', $appBaseDirCode);

        /** @var array<string, array<string>> $psr0Autoloads */
        $psr0Autoloads = [];

        /** @var array<string, array<string>> $psr4Autoloads */
        $psr4Autoloads = [];
        $classmapAutoloads = [];

        $packages        = $this->getInstalledPackages($package);
        $devPackageNames = $package->getLocalRepository()->getDevPackageNames();

        $autoloads = $this->parseAutoloads($packages, $this->devMode ? [] : $devPackageNames, $package);

        // PSR-0
        foreach ($autoloads["psr-0"] as $namespace => $paths) {
            $exportedPaths = [];

            foreach ($paths as $path) {
                $exportedPaths[] = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
            }

            $psr0Autoloads[$namespace] = $exportedPaths;
        }

        // PSR-4
        foreach ($autoloads["psr-4"] as $namespace => $paths) {
            $exportedPaths = [];

            foreach ($paths as $path) {
                $exportedPaths[] = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
            }

            $psr4Autoloads[$namespace] = $exportedPaths;
        }

        // CLASSMAP
        $ambiguousClasses = [];
        $scannedFiles     = [];

        foreach ($autoloads["classmap"] as $classmapPath) {
            $classmapAutoloads = $this->addClassmapCode($filesystem, $basePath, $vendorPath, $classmapPath, $classmapAutoloads, $ambiguousClasses, $scannedFiles);
        }

        // private function addClassMapCode(Filesystem $filesystem, string $basePath, string $vendorPath, array $classmapPaths, array $classmap, array &$ambiguousClasses, array &$scannedFiles, ?string $namespaceFilter = null, ?string $autoloadType = null)

        $namespacesToScan = [];

        // Scan the PSR-0/4 directories for class files, and add them to the class map
        foreach (["psr-0", "psr-4"] as $psrType) {
            foreach ($autoloads[$psrType] as $namespace => $paths) {
                $namespacesToScan[$namespace][] = ["paths" => $paths, "type" => $psrType];
            }
        }

        krsort($namespacesToScan);

        foreach ($namespacesToScan as $namespace => $groups) {
            foreach ($groups as $group) {
                foreach ($group["paths"] as $dir) {
                    $dir = $filesystem->normalizePath($filesystem->isAbsolutePath($dir) ? $dir : $basePath.'/'.$dir);
                    if (!is_dir($dir)) {
                        continue;
                    }

                    $classmapAutoloads = $this->addClassMapCode($filesystem, $basePath, $vendorPath, $dir, $classmapAutoloads, $ambiguousClasses, $scannedFiles, $namespace, $group['type']);
                }
            }
        }

        foreach ($ambiguousClasses as $className => $ambigiousPaths) {
            $cleanPath = str_replace(array('$vendorDir . \'', '$baseDir . \'', "',\n"), array($vendorPath, $basePath, ''), $classmapAutoloads[$className]);

            $this->io->writeError(
                '<warning>Warning: Ambiguous class resolution, "'.$className.'"'.
                ' was found '. (count($ambigiousPaths) + 1) .'x: in "'.$cleanPath.'" and "'. implode('", "', $ambigiousPaths) .'", the first will be used.</warning>'
            );
        }

        $classmapAutoloads['Composer\\InstalledVersions'] = "\$vendorDir . '/composer/InstalledVersions.php',\n";
        ksort($classmapAutoloads);

        $classmap = [];

        foreach ($classmapAutoloads as $class => $code) {
            $classmap[$class] = $code;
        }

        /*
        $includeFilesFilePath = $targetDir.'/autoload_files.php';
        if ($includeFilesFileContents = $this->getIncludeFilesFile($autoloads['files'], $filesystem, $basePath, $vendorPath, $vendorPathCode52, $appBaseDirCode)) {
            $filesystem->filePutContentsIfModified($includeFilesFilePath, $includeFilesFileContents);
        } elseif (file_exists($includeFilesFilePath)) {
            unlink($includeFilesFilePath);
        }
        */
        $includeFilesFileContents = "";

        $filesystem->filePutContentsIfModified("${vendorPath}/autoload.php", $this->getAutoloadFile($filesystem->findShortestPathCode($vendorPath, realpath($targetDir), true)));
        $filesystem->filePutContentsIfModified("${targetDir}/autoload_real.php", $this->getAutoloadRealFile());
        $filesystem->filePutContentsIfModified("${targetDir}/autoload_static.php", $this->getStaticFile($targetDir, $vendorPath, $basePath, $staticPhpVersion, $psr0Autoloads, $psr4Autoloads, $classmap, []));

        $filesystem->safeCopy(__DIR__ . "/ClassLoader.php", $targetDir . "/ClassLoader.php");

        return count($classmap);
    }

    private function getAutoloadFile(string $autoloadDirectory): string
    {
        return <<<AUTOLOAD
<?php
declare(strict_types = 1);

require_once $autoloadDirectory . '/autoload_real.php';

return ConductorAutoloader::init();

AUTOLOAD;
    }

    private function getAutoloadRealFile(): string
    {
        return <<<AUTOLOAD_REAL
<?php
declare(strict_types = 1);

class ConductorAutoloader
{
    private static \$loader;

    public static function loadClassLoader(\$class)
    {
        if (\$class !== "Conductor\\Autoload\\ClassLoader") {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Conductor\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (isset(self::\$loader)) {
            return self::\$loader;
        }

        spl_autoload_register(array('ConductorAutoloader', 'loadClassLoader'), true, true);
        self::\$loader = \$loader = new \\Composer\\Autoload\\ClassLoader();
        spl_autoload_unregister(array('ConductorAutoloader', 'loadClassLoader'));

            require __DIR__ . '/autoload_static.php';

            call_user_func(\Composer\Autoload\ComposerStaticInit::getInitializer(\$loader));
        
        \$loader->setClassMapAuthoritative(true);
        \$loader->register(true);
        
        \$includeFiles = Composer\Autoload\ComposerStaticInit::\$files;
        
        foreach (\$includeFiles as \$fileIdentifier => \$file) {
            composerRequire(\$fileIdentifier, \$file);
        }

        return \$loader;
    }
    
    function composerRequire(\$fileIdentifier, \$file)
    {
        if (empty(\$GLOBALS['__composer_autoload_files'][\$fileIdentifier])) {
            require \$file;

            \$GLOBALS['__composer_autoload_files'][\$fileIdentifier] = true;
        }
    }
}

AUTOLOAD_REAL;
    }

    private function getStaticFile($targetDir, $vendorPath, $basePath, &$staticPhpVersion, $psr0Autoloads, $psr4Autoloads, $classmapAutoloads, $fileAutoloads)
    {
        $file = <<<HEADER
<?php
declare(strict_types = 1);

namespace Conductor\Autoload;

class ConductorAutoloader
{

HEADER;

        $loader = new ClassLoader();

        foreach ($psr0Autoloads as $namespace => $path) {
            $loader->set($namespace, $path);
        }

        foreach ($psr4Autoloads as $namespace => $path) {
            $loader->setPsr4($namespace, $path);
        }

        if ($classmapAutoloads) {
            $loader->addClassMap($classmapAutoloads);
        }

        $filesystem = new Filesystem();

        $vendorPathCode = ' => ' . $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true, true) . " . '/";
        $vendorPharPathCode = ' => \'phar://\' . ' . $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true, true) . " . '/";
        $appBaseDirCode = ' => ' . $filesystem->findShortestPathCode(realpath($targetDir), $basePath, true, true) . " . '/";
        $appBaseDirPharCode = ' => \'phar://\' . ' . $filesystem->findShortestPathCode(realpath($targetDir), $basePath, true, true) . " . '/";

        $absoluteVendorPathCode = ' => ' . substr(var_export(rtrim($vendorPath, '\\/') . '/', true), 0, -1);
        $absoluteVendorPharPathCode = ' => ' . substr(var_export(rtrim('phar://' . $vendorPath, '\\/') . '/', true), 0, -1);
        $absoluteAppBaseDirCode = ' => ' . substr(var_export(rtrim($basePath, '\\/') . '/', true), 0, -1);
        $absoluteAppBaseDirPharCode = ' => ' . substr(var_export(rtrim('phar://' . $basePath, '\\/') . '/', true), 0, -1);

        $initializer = '';
        $prefix = "\0Conductor\Autoload\ClassLoader\0";
        $prefixLen = strlen($prefix);

        $maps = array('files' => $fileAutoloads);

        foreach ((array) $loader as $prop => $value) {
            if ($value && 0 === strpos($prop, $prefix)) {
                $maps[substr($prop, $prefixLen)] = $value;
            }
        }

        foreach ($maps as $prop => $value) {
            $value = strtr(
                var_export($value, true),
                array(
                    $absoluteVendorPathCode => $vendorPathCode,
                    $absoluteVendorPharPathCode => $vendorPharPathCode,
                    $absoluteAppBaseDirCode => $appBaseDirCode,
                    $absoluteAppBaseDirPharCode => $appBaseDirPharCode,
                )
            );
            $value = ltrim(preg_replace('/^ */m', '    $0$0', $value));

            $file .= sprintf("    public static $%s = %s;\n\n", $prop, $value);
            if ('files' !== $prop) {
                $initializer .= "            \$loader->$prop = ComposerStaticInit::\$$prop;\n";
            }
        }

        return $file . <<<INITIALIZER
    public static function getInitializer(ClassLoader \$loader)
    {
        return \Closure::bind(function () use (\$loader) {
$initializer
        }, null, ClassLoader::class);
    }
}

INITIALIZER;
    }

    /**
     * Builds a list of packages that need to be autoloaded to run a given monorepo package.
     *
     * @return array<PackageInterface>
     *
     * @throws Exception
     */
    private function getInstalledPackages(MonorepoPackage $package): array
    {
        $installedPackages = $package->getLocalRepository()->getCanonicalPackages();

        foreach ($installedPackages as $installedPackage) {
            $this->validatePackageAutoloadConfig($installedPackage);
        }

        array_unshift($installedPackages, $package);

        return $installedPackages;
    }

    /**
     * Ensures that a package has valid autoload configurations.
     *
     * A package has valid configurations if:
     *     1. PSR-0 and PSR-4 configurations are not mixed. PSR-4 configurations may not use the PSR-0 `target-dir` configuration.
     *     2. PSR-4 namespaces end with the namespace separator \.
     *
     * @throws Exception when a package does not have a valid PSR-0 or PSR-4 configuration
     */
    private function validatePackageAutoloadConfig(PackageInterface $package): void
    {
        $autoload = $package->getAutoload();

        if (!empty($autoload["psr-4"]) && $package->getTargetDir() !== null) {
            $packageName = $package->getName();

            throw new Exception("PSR-4 autoloading is incompatible with the target-dir property. Remove the target-dir property in package '$packageName'.");
        }

        if (!empty($autoload["psr-4"])) {
            foreach ($autoload["psr-4"] as $namespace => $dir) {
                if ($namespace !== "" && substr($namespace, -1) !== "\\") {
                    throw new Exception("PSR-4 namespaces must end with a namespace separator. '$namespace' does not, use '$namespace\\'.");
                }
            }
        }

        if (!empty($autoload["psr-0"]) && !is_array($autoload["psr-0"])) {
            throw new Exception("The 'psr-0' autoload configuration must be an array.");
        }

        if (!empty($autoload["psr-4"]) && !is_array($autoload["psr-4"])) {
            throw new Exception("The 'psr-4' autoload configuration must be an array.");
        }

        if (!empty($autoload["classmap"]) && !is_array($autoload["classmap"])) {
            throw new Exception("The 'classmap' autoload configuration must be an array.");
        }

        if (!empty($autoload["files"]) && !is_array($autoload["files"])) {
            throw new Exception("The 'files' autoload configuration must be an array.");
        }
    }

    /**
     * Compiles an ordered list of namespace => path mappings
     *
     * @param  array<PackageInterface> $installedPackages
     * @param  array<string>           $filterPackageNames
     *
     * @return array<string, array<string, array<string>>>   ["psr-0" => ["Ns\\Foo" => ["installDir"]]]
     */
    private function parseAutoloads(array $installedPackages, array $filterPackageNames, MonorepoPackage $rootPackage): array
    {
        array_shift($installedPackages);

        // Remove the given devPackageNames
        $installedPackages = array_filter($installedPackages, static function ($item) use ($filterPackageNames) {
            return !in_array($item->getName(), $filterPackageNames, true);
        });

        $sortedPackages   = $this->sortPackages($installedPackages);
        $sortedPackages[] = $rootPackage;

        array_unshift($installedPackages, $rootPackage);

        $psr0     = $this->parseAutoloadType($installedPackages, "psr-0", $rootPackage);
        $psr4     = $this->parseAutoloadType($installedPackages, "psr-4", $rootPackage);
        $classmap = $this->parseAutoloadType(array_reverse($sortedPackages), "classmap", $rootPackage);
        $files    = $this->parseAutoloadType($sortedPackages, "files", $rootPackage);

        krsort($psr0);
        krsort($psr4);

        return [
            "psr-0"    => $psr0,
            "psr-4"    => $psr4,
            "classmap" => $classmap,
            "files"    => $files,
        ];
    }

    /**
     * Given a list of packages, parse their autoloads into a namespace => [paths] format.
     *
     * @param array<PackageInterface> $autoloadPackages
     *
     * @return array<string, array<string>>
     */
    private function parseAutoloadType(array $autoloadPackages, string $type, MonorepoPackage $rootPackage): array
    {
        $autoloads = [];

        foreach ($autoloadPackages as $package) {
            $autoload = $package->getAutoload();

            // Only include local dev-autoload dependencies for the root package. Package
            // dependencies' dev-autoload configurations are not loaded.
            if ($this->devMode && $package === $rootPackage) {
                $autoload = array_merge_recursive($autoload, $package->getDevAutoload());
            }

            if (!isset($autoload[$type])) {
                continue;
            }

            $installPath = $rootPackage->getInstallationManager()->getInstallPath($package);

            foreach ($autoload[$type] as $namespace => $paths) {
                foreach ((array)$paths as $path) {
                    $relativePath = empty($installPath) ? (empty($path) ? '.' : $path) : $installPath.'/'.$path;

                    if ($type === 'files') {
                        $autoloads[$this->getFileIdentifier($package, $path)] = $relativePath;
                        continue;
                    }
                    if ($type === 'classmap') {
                        $autoloads[] = $relativePath;
                        continue;
                    }

                    $autoloads[$namespace][] = $relativePath;
                }
            }
        }

        return $autoloads;
    }




    // // $classMap = $this->addClassMapCode($filesystem, $basePath, $vendorPath, $dir, [], null, null, $classMap, $ambiguousClasses, $scannedFiles);

    private function addClassMapCode(Filesystem $filesystem, string $basePath, string $vendorPath, string $classmapPath, array $classmap, array &$ambiguousClasses, array &$scannedFiles, ?string $namespaceFilter = null, ?string $autoloadType = null)
    {
        foreach ($this->generateClassMap($classmapPath, $scannedFiles, $namespaceFilter, $autoloadType) as $class => $path) {
            $pathCode = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);

            if (!isset($classmap[$class])) {
                $classmap[$class] = $pathCode;
            }

            if ($classmap[$class] !== $pathCode) {
                $ambiguousClasses[$class][] = $path;
            }
        }

        return $classmap;
    }

    /**
     * @param array<string> $searchDirectories
     *
     * @return array<class-string, string>
     */
    private function generateClassMap(string $searchDirectory, array &$scannedFiles, ?string $namespaceFilter = null, ?string $autoloadType = null): array
    {
        return ClassMapGenerator::createMap($searchDirectory, $this->io, $namespaceFilter, $autoloadType, $scannedFiles);
    }





    /**
     * Registers an autoloader based on an autoload map returned by parseAutoloads
     *
     * @param  array       $autoloads see parseAutoloads return value
     * @return ClassLoader
     */
    public function createLoader(array $autoloads)
    {
        $loader = new ClassLoader();

        if (isset($autoloads['psr-0'])) {
            foreach ($autoloads['psr-0'] as $namespace => $path) {
                $loader->add($namespace, $path);
            }
        }

        if (isset($autoloads['psr-4'])) {
            foreach ($autoloads['psr-4'] as $namespace => $path) {
                $loader->addPsr4($namespace, $path);
            }
        }

        if (isset($autoloads['classmap'])) {
            $excluded = null;
            if (!empty($autoloads['exclude-from-classmap'])) {
                $excluded = '{(' . implode('|', $autoloads['exclude-from-classmap']) . ')}';
            }

            $scannedFiles = array();
            foreach ($autoloads['classmap'] as $dir) {
                try {
                    $loader->addClassMap($this->generateClassMap($dir, $excluded, null, null, false, $scannedFiles));
                } catch (\RuntimeException $e) {
                    $this->io->writeError('<warning>'.$e->getMessage().'</warning>');
                }
            }
        }

        return $loader;
    }

    protected function getIncludeFilesFile(array $files, Filesystem $filesystem, $basePath, $vendorPath, $vendorPathCode, $appBaseDirCode)
    {
        $filesCode = '';
        foreach ($files as $fileIdentifier => $functionFile) {
            $filesCode .= '    ' . var_export($fileIdentifier, true) . ' => '
                . $this->getPathCode($filesystem, $basePath, $vendorPath, $functionFile) . ",\n";
        }

        if (!$filesCode) {
            return false;
        }

        return <<<EOF
<?php

// autoload_files.php @generated by Composer

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(
$filesCode);

EOF;
    }

    private function getPathCode(Filesystem $filesystem, string $basePath, string $vendorPath, string $path): string
    {
        if (!$filesystem->isAbsolutePath($path)) {
            $path = "${basePath}/${path}";
        }
        $path = $filesystem->normalizePath($path);

        $baseDir = '';
        if (strpos($path.'/', $vendorPath.'/') === 0) {
            $path = substr($path, strlen($vendorPath));
            $baseDir = '$vendorDir';

            if ($path !== false) {
                $baseDir .= " . ";
            }
        } else {
            $path = $filesystem->normalizePath($filesystem->findShortestPath($basePath, $path, true));
            if (!$filesystem->isAbsolutePath($path)) {
                $baseDir = '$baseDir . ';
                $path = '/' . $path;
            }
        }

        if (strpos($path, '.phar') !== false) {
            $baseDir = "'phar://' . " . $baseDir;
        }

        return "${vendorPath}${path}";

        return $baseDir . (($path !== false) ? var_export($path, true) : "");
    }

    protected function getFileIdentifier(PackageInterface $package, $path)
    {
        return md5($package->getName() . ':' . $path);
    }

    /**
     * Sorts packages by dependency weight.
     *
     * Packages of equal weight retain the original order.
     *
     * @param  array<PackageInterface> $unsortedPackages
     *
     * @return array<PackageInterface>
     */
    private function sortPackages(array $unsortedPackages): array
    {
        $packages = [];

        foreach ($unsortedPackages as $package) {
            $packages[$package->getName()] = $package;
        }

        /** @var array<PackageInterface> $sortedPackages */
        $sortedPackages = PackageSorter::sortPackages($packages);
        $returnPackages = [];

        foreach ($sortedPackages as $package) {
            $returnPackages[] = $packages[$package->getName()];
        }

        return $returnPackages;
    }
}
