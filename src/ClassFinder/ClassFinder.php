<?php

namespace Arlisaha\Chozo\ClassFinder;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function file_get_contents;
use function in_array;
use function is_string;
use function token_get_all;
use function token_name;
use const TOKEN_PARSE;

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
     * ClassFinder constructor.
     *
     * @param string $appRoot
     */
    public function __construct(string $appRoot)
    {
        $this->appRoot        = $appRoot;
        $this->rootNamespaces = json_decode(file_get_contents($this->appRoot . 'composer.json'), true)['autoload']['psr-4'];
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
        $content = file_get_contents($filename);

        preg_match('#^(namespace)(\\s+)([A-Za-z0-9\\\\]+?)(\\s*);#sm', $content, $namespaceMatch);
        preg_match('#^(class)(\\s+)([A-Za-z0-9\\\\]+?)\\s#sm', $content, $classMatch);

        if ($namespaceMatch && array_key_exists(3, $namespaceMatch) && $classMatch && array_key_exists(3, $classMatch)) {
            return sprintf('%s\\%s', $namespaceMatch[3], $classMatch[3]);
        }

        return null;
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
        return array_reduce($namespaces , function(array $carry, string $namespace) {
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