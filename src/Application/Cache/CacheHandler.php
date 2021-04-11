<?php

namespace Arlisaha\Chozo\Application\Cache;

use Arlisaha\Chozo\Application\PathBuilder\PathBuilder;
use Arlisaha\Chozo\Exception\CacheDirectoryException;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function mkdir;

final class CacheHandler
{
    /**
     * @var string
     */
    private $path;

    /**
     * @var PathBuilder
     */
    private $pathBuilder;

    /**
     * CacheHandler constructor.
     *
     * @param string      $cacheDirectory
     * @param PathBuilder $pathBuilder
     * @param int         $permission
     *
     * @throws CacheDirectoryException
     */
    public function __construct(string $cacheDirectory, PathBuilder $pathBuilder, int $permission)
    {
        if (!file_exists($cacheDirectory) && !mkdir($cacheDirectory, $permission, true)) {
            throw new CacheDirectoryException();
        }

        $this->path        = $cacheDirectory;
        $this->pathBuilder = $pathBuilder;
    }

    /**
     * @param string $expectedFilename
     *
     * @return string|null
     */
    public function get(string $expectedFilename): ?string
    {
        $filename = $this->pathBuilder->getAbsolutePathFromArray([$this->getDirectory(), $expectedFilename]);

        if (!file_exists($filename)) {
            return null;
        }

        $res = file_get_contents($filename);

        return (false === $res ? null : $res);
    }

    /**
     * @param string $expectedFilename
     * @param string $data
     * @param int    $flags
     *
     * @return bool
     */
    public function set(string $expectedFilename, string $data, int $flags = 0): bool
    {
        $filename = $this->pathBuilder->getAbsolutePathFromArray([$this->getDirectory(), $expectedFilename]);
        $res      = file_put_contents($filename, $data, $flags);

        return !(false === $res);
    }

    /**
     * @return string
     */
    public function getDirectory(): string
    {
        return $this->path;
    }
}