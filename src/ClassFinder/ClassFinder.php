<?php

namespace Arlisaha\Chozo\ClassFinder;

use Arlisaha\Chozo\Exception\InvalidJsonException;
use Arlisaha\Chozo\Exception\NoComposerFileFoundException;
use Arlisaha\Chozo\Exception\NoPsr4AutoloadKeyFound;
use RecursiveDirectoryIterator;
use SplFileInfo;
use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_reduce;
use function explode;
use function file_get_contents;
use function implode;
use function json_decode;
use function json_last_error;
use function ltrim;
use function preg_match;
use function sprintf;
use function strlen;
use function strpos;
use function substr;
use const DIRECTORY_SEPARATOR;
use const JSON_ERROR_NONE;

class ClassFinder implements ClassFinderInterface
{
    /**
     * @var string
     */
    private $appRoot;

    /**
     * @var string
     */
    private $rootNamespaces;

    /**
     * @var array<string, string>
     */
    private $store;

    /**
     * ClassFinder constructor.
     *
     * @param string $appRoot
     */
    public function __construct(string $appRoot)
    {
        $this->store = [];
        $this->appRoot = $appRoot;

        $path = $this->appRoot . 'composer.json';
        if (!($content = file_get_contents($path))) {
            throw new NoComposerFileFoundException($path);
        }
        $decoded = json_decode($content, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidJsonException($path);
        }
        if (!array_key_exists('autoload', $decoded) || !array_key_exists('psr-4', $decoded['autoload'])) {
            throw new NoPsr4AutoloadKeyFound();
        }

        $this->rootNamespaces = $decoded['autoload']['psr-4'];
    }

    /**
     * @param string $namespace
     *
     * @return string[]|null
     */
    final protected function findRootNamespace(string $namespace): ?array
    {
        foreach ($this->rootNamespaces as $rootNamespace => $path) {
            if (0 === strpos($namespace, $rootNamespace)) {
                return ['namespace' => $rootNamespace, 'path' => $path];
            }
        }

        return null;
    }

    /**
     * @param string $rootNamespacePath
     *
     * @return string
     */
    final protected function getRootDirectory(string $rootNamespacePath): string
    {
        return $this->appRoot . ltrim($rootNamespacePath, '/\\' . DIRECTORY_SEPARATOR);
    }

    /**
     * @param string $rootNamespace
     * @param string $path
     * @param string $namespace
     *
     * @return string
     */
    final protected function namespaceToDirectory(string $rootNamespace, string $path, string $namespace): string
    {
        return $this->getRootDirectory($path) .
            implode(DIRECTORY_SEPARATOR,
                explode('\\', ltrim(substr($namespace, strlen($rootNamespace)), '\\'))
            ) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param string $filename
     *
     * @return string|null
     */
    final protected function getFullyQualifiedClassnameFromPath(string $filename): ?string
    {
        if(array_key_exists($filename, $this->store)) {
            return $this->store[$filename];
        }

        $content = file_get_contents($filename);

        preg_match('#^(namespace)(\\s+)([A-Za-z0-9\\\\]+?)(\\s*);#sm', $content, $namespaceMatch);
        preg_match('#^(class)(\\s+)([A-Za-z0-9\\\\]+?)\\s#sm', $content, $classMatch);

        $res = null;
        if ($namespaceMatch && array_key_exists(3, $namespaceMatch) && $classMatch && array_key_exists(3, $classMatch)) {
            $res = sprintf('%s\\%s', $namespaceMatch[3], $classMatch[3]);
        }

        $this->store[$filename] = $res;

        return $res;
    }

    /**
     * @param string $directory
     *
     * @return array
     */
    final protected function findClassesInDirectory(string $directory): array
    {
        $files = new RecursiveDirectoryIterator($directory);

        $fullyQualifiedClassnames = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path                            = $file->getRealPath();
            $fullyQualifiedClassnames[$path] = $this->getFullyQualifiedClassnameFromPath($path);
        }

        return array_filter($fullyQualifiedClassnames);
    }

    /**
     * @inheritDoc
     */
    public function getRootNamespaces(): array
    {
        return $this->rootNamespaces;
    }

    /**
     * @inheritDoc
     */
    public function getClassesInNamespace(string $namespace): array
    {
        $rootNamespace     = $this->findRootNamespace($namespace);
        $matchingDirectory = $this->namespaceToDirectory($rootNamespace['namespace'], $rootNamespace['path'], $namespace);

        return $this->findClassesInDirectory($matchingDirectory);
    }

    /**
     * @inheritDoc
     */
    public function getClassesInNamespaces(array $namespaces): array
    {
        return array_reduce($namespaces, function (array $carry, string $namespace) {
            return array_merge($carry, $this->getClassesInNamespace($namespace));
        }, []);
    }

    /**
     * @inheritDoc
     */
    public function namespaceHasClasses(string $namespace): bool
    {
        return (bool)$this->getClassesInNamespace($namespace);
    }

    /**
     * @inheritDoc
     */
    public function namespacesHasClasses(array $namespaces): array
    {
        $classes = [];
        foreach ($namespaces as $namespace) {
            $classes[$namespace] = $this->namespaceHasClasses($namespace);
        }

        return $classes;
    }
}