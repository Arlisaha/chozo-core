<?php

namespace Arlisaha\Chozo\ClassFinder;

interface ClassFinderInterface
{
    /**
     * @return string[]
     */
    public function getRootNamespaces(): array;

    /**
     * @param string $namespace
     *
     * @return string[]
     */
    public function getClassesInNamespace(string $namespace): array;

    /**
     * @param array $namespaces
     *
     * @return string[]
     */
    public function getClassesInNamespaces(array $namespaces): array;

    /**
     * @param string $namespace
     *
     * @return bool
     */
    public function namespaceHasClasses(string $namespace): bool;

    /**
     * @param array $namespaces
     *
     * @return array<string, bool>
     */
    public function namespacesHasClasses(array $namespaces): array;
}