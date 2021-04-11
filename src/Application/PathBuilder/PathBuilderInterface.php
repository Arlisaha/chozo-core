<?php

namespace Arlisaha\Chozo\Application\PathBuilder;

interface PathBuilderInterface
{
    /**
     * @return string
     */
    public function getRootDir(): string;

    /**
     * @param string $relativePath
     *
     * @return string
     */
    public function getAbsolutePath(string $relativePath): string;

    /**
     * @param array $relativePathParts
     *
     * @return string
     */
    public function getAbsolutePathFromArray(array $relativePathParts): string;
}