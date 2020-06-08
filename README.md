# Composer Monorepo Plugin

    This project is still in development. Please provide feedback.

# TODO: Binaries

## Features

#### General
- Centrally manage the dependencies of a repository while still being able to
  explicitly define the dependencies of each package.

#### Tests
- Run tests across all repositories at once
- Run tests across all repositories at once, only on files that have changed
- Run tests across all repositories at once, only on files that have changed
  _and_ files that depend upon them.

#### Archive
- Archive all repositories
- Archive changed repositories

## Using

Repositories managed using this plugin contain two kind of packages:

1. Composer packages defined by a single `composer.json` residing in the
   repository root.
2. Many local packages in sub-foldersassar2ceeeim of the project root, defined by a
   `monorepo.json`. This is similar to a `composer.json` file. The file
   name can be customized in `composer.json`.

## How it works





## Usage

Whenever Composer generates autoload files (during install, update or
dump-autoload) it will find all sub-directories with `monorepo.json` files and
generate sub-package autoloaders for them.

You can execute the autoload generation step for just the subpackages by
calling:

    $ composer monorepo:build

You create a `composer.json` file in the root of your project and use
this single source of vendor libraries across all of your own packages.

This sounds counter-intuitive to the Composer approach at first, but
it simplifies dependency management for a big project massively. Usually
if you are using a composer.json per package, you have mass update sprees
where you upate some basic library like "symfony/dependency-injection" in
10-20 packages or worse, have massively out of date packages and
many different versions everywhere.

Then, each of your own package contains a `monorepo.json` using almost
the same syntax as Composer:

    {
        "deps": [
            "components/Foo",
            "vendor/symfony/symfony"
        ],
        "autoload": {
            "psr-0": {"Foo\\": "src/"}
        }
    }

You can then run `composer dump-autoload` in the root directory next to
composer.json and this plugin will detect all packages, generate a custom
autoloader for each one by simulating `composer dump-autoload` as if a
composer.json were present in the subdirectory.

This plugin will resolve all dependencies (without version constraints, because it
is assumed the code is present in the correct versions in a monolithic
repository).

Package names in `deps` are the relative directory names from the project root,
*not* Composer package names.

You can just `require "vendor/autoload.php;` in every package as if you were using Composer.
Only autoloads from the `monorepo.json` are included, which means all dependencies must be explicitly
specified.

## Configuration Schema monorepo.json

For each package in your monolithic repository you have to add `monorepo.json`
that borrows from `composer.json` format. The following keys are usable:

- `autoload` - configures the autoload settings for the current package classes and files.
- `autoload-dev` - configures dev autoload requirements. Currently *always* evalauted.
- `deps` - configures the required dependencies in an array (no key-value pairs with versions)
  using the relative path to the project root directory as a package name.
- `deps-dev` - configures the required dev dependencies

## Git Integration for Builds

In a monorepo, for every git commit range you want to know which components changed.
You can test with the `git-changed?` command:

```bash
composer monorepo:git-changed? components/foo $TRAVIS_COMMIT_RANGE
if [ $? -eq 0 ]; then ant build fi
```

## License

MIT
