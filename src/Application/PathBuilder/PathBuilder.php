<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\PathBuilder;

use function implode;
use function in_array;
use function realpath;
use const DIRECTORY_SEPARATOR;

class PathBuilder
{
    protected const SEPARATORS = [DIRECTORY_SEPARATOR, '/', '\\'];

    /**
     * @var string
     */
    private $rootDir;

    /**
     * PathBuilder constructor.
     *
     * @param string $rootDir
     */
    public function __construct(string $rootDir)
    {
        $this->rootDir = $this->getRtrimmedRealPath($rootDir);
    }

    /**
     * @return string
     */
    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * @param string $relativePath
     *
     * @return string
     */
    public function getAbsolutePath(string $relativePath): string
    {
        if (!in_array($relativePath[0], static::SEPARATORS, true)) {
            $relativePath = DIRECTORY_SEPARATOR . $relativePath;
        }

        return $this->getRtrimmedRealPath($this->getRootDir() . $relativePath);
    }

    /**
     * @param array $relativePathParts
     *
     * @return string
     */
    public function getAbsolutePathFromArray(array $relativePathParts): string
    {
        return $this->getAbsolutePath(implode(DIRECTORY_SEPARATOR, $relativePathParts));
    }

    /**
     * @param string $path
     *
     * @return string
     */
    protected function getRtrimmedRealPath(string $path): string
    {
        return rtrim(realpath($this->getRootDir() . $path), implode('', static::SEPARATORS));
    }
}