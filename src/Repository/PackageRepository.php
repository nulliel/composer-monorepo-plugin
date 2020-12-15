<?php
declare(strict_types = 1);

namespace Conductor\Repository;

use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\InvalidRepositoryException;
use Composer\Repository\PlatformRepository;
use Composer\Repository\WritableArrayRepository;
use Composer\Util\Filesystem;
use Conductor\Io\File;
use Conductor\Package\MonorepoPackage;
use Throwable;
use UnexpectedValueException;

/**
 * A repository containing a package's installed dependencies
 */
class PackageRepository extends WritableArrayRepository implements InstalledRepositoryInterface
{
    private const INSTALL_FILE_PATH = "composer/installed.json";

    private File $installFile;

    public function __construct(private MonorepoPackage $package)
    {
        parent::__construct();

        $this->installFile = $package->getVendorDirectory()->withPath(self::INSTALL_FILE_PATH);
    }

    protected function initialize(): void
    {
        parent::initialize();

        $file = $this->installFile->toJsonFile();

        if (!$file->exists()) {
            return;
        }

        try {
            $data     = $file->read();
            $packages = $data["packages"] ?? $data;

            if (isset($packages["packages"])) {
                $packages = $packages["packages"];
            }

            if (isset($data["dev-package-names"])) {
                $this->setDevPackageNames($data["dev-package-names"]);
            }

            if (!is_array($packages)) {
                throw new UnexpectedValueException("Could not parse package list from the repository");
            }
        } catch (Throwable $e) {
            throw new InvalidRepositoryException("Invalid repository data in " . $file->getPath() . ", packages could not be loaded: [" . get_class($e) . "] " . $e->getMessage());
        }

        $loader = new ArrayLoader(null, true);

        foreach ($packages as $packageData) {
            $package = $loader->load($packageData);

            $package->setDistType("path");
            $package->setDistUrl($this->package->monorepo->getInstallationManager()->getInstallPath($package));

            $this->addPackage($package);
        }
    }

    public function reload(): void
    {
        $this->packages = null;
        $this->initialize();
    }

    /**
     * @inheritDoc
     */
    public function write($devMode, InstallationManager $installationManager): void
    {
        $data = [
            "packages"          => [],
            "dev"               => $devMode,
            "dev-package-names" => [],
        ];

        $dumper = new ArrayDumper();
        $fs     = new Filesystem();

        $repositoryDirectory = $fs->normalizePath($this->installFile->getPath());

        foreach ($this->getCanonicalPackages() as $package) {
            $packageArray = $dumper->dump($package);
            $path         = $installationManager->getInstallPath($package);

            $packageArray["install-path"] = $path !== "" && $path !== null
                ? $fs->findShortestPath($repositoryDirectory, $fs->isAbsolutePath($path) ? $path : getcwd() . "/" . $path, true)
                : null;
            $data["packages"][] = $packageArray;

            if (in_array($package->getName(), $this->devPackageNames, true)) {
                $data["dev-package-names"][] = $package->getName();
            }
        }

        usort($data["packages"], static fn($a, $b) => $a["name"] <=> $b["name"]);

        $this->installFile->toJsonFile()->write($data);

        $versions = ["versions"=> []];
        $packages = $this->getPackages();

        $packages[] = $rootPackage = $this->package;

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }

            $reference = null;
            if ($package->getInstallationSource()) {
                $reference = $package->getInstallationSource() === 'source' ? $package->getSourceReference() : $package->getDistReference();
            }
            if (null === $reference) {
                $reference = ($package->getSourceReference() ?: $package->getDistReference()) ?: null;
            }

            $versions['versions'][$package->getName()] = array(
                'pretty_version' => $package->getPrettyVersion(),
                'version' => $package->getVersion(),
                'aliases' => array(),
                'reference' => $reference,
            );
            if ($package instanceof RootPackageInterface) {
                $versions['root'] = $versions['versions'][$package->getName()];
                $versions['root']['name'] = $package->getName();
            }
        }

        // add provided/replaced packages
        foreach ($packages as $package) {
            foreach ($package->getReplaces() as $replace) {
                // exclude platform replaces as when they are really there we can not check for their presence
                if (PlatformRepository::isPlatformPackage($replace->getTarget())) {
                    continue;
                }
                $replaced = $replace->getPrettyConstraint();
                if ($replaced === 'self.version') {
                    $replaced = $package->getPrettyVersion();
                }
                if (!isset($versions['versions'][$replace->getTarget()]['replaced']) || !in_array($replaced, $versions['versions'][$replace->getTarget()]['replaced'], true)) {
                    $versions['versions'][$replace->getTarget()]['replaced'][] = $replaced;
                }
            }
            foreach ($package->getProvides() as $provide) {
                // exclude platform provides as when they are really there we can not check for their presence
                if (PlatformRepository::isPlatformPackage($provide->getTarget())) {
                    continue;
                }
                $provided = $provide->getPrettyConstraint();
                if ($provided === 'self.version') {
                    $provided = $package->getPrettyVersion();
                }
                if (!isset($versions['versions'][$provide->getTarget()]['provided']) || !in_array($provided, $versions['versions'][$provide->getTarget()]['provided'], true)) {
                    $versions['versions'][$provide->getTarget()]['provided'][] = $provided;
                }
            }
        }

        // add aliases
        foreach ($packages as $package) {
            if (!$package instanceof AliasPackage) {
                continue;
            }
            $versions['versions'][$package->getName()]['aliases'][] = $package->getPrettyVersion();
            if ($package instanceof RootPackageInterface) {
                $versions['root']['aliases'][] = $package->getPrettyVersion();
            }
        }

        ksort($versions['versions']);
        ksort($versions);

        $fs->filePutContentsIfModified(dirname($repositoryDirectory) . '/installed.php', '<?php return '.var_export($versions, true).';'."\n");
        $installedVersionsClass = file_get_contents(__DIR__.'/../InstalledVersions.php');
        $installedVersionsClass = str_replace('private static $installed;', 'private static $installed = '.var_export($versions, true).';', $installedVersionsClass);
        $fs->filePutContentsIfModified(dirname($repositoryDirectory).'/InstalledVersions.php', $installedVersionsClass);

    }

    public function isFresh(): bool
    {
        return $this->installFile->exists();
    }
}
