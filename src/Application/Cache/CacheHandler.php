<?php

namespace Arlisaha\Chozo\Application\Cache;

use Arlisaha\Chozo\Application\PathBuilder\PathBuilder;
use Arlisaha\Chozo\Exception\CacheDirectoryException;
use Arlisaha\Chozo\Exception\InvalidPathException;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_string;
use function serialize;
use function unserialize;

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
     *
     * @throws CacheDirectoryException
     */
    public function __construct(string $cacheDirectory, PathBuilder $pathBuilder)
    {
        if (!file_exists($cacheDirectory) && !$pathBuilder->createPath($cacheDirectory)) {
            throw new CacheDirectoryException();
        }

        $this->path        = $cacheDirectory;
        $this->pathBuilder = $pathBuilder;
    }

    /**
     * @param string $expectedFilename
     * @param bool   $unserialize
     *
     * @return string|mixed|null
     */
    public function get(string $expectedFilename, bool $unserialize = false)
    {
        try {
            $filename = $this->pathBuilder->getAbsolutePathFromArray([$this->getDirectory(), $expectedFilename]);
        } catch (InvalidPathException $exception) {
            return null;
        }

        $res = file_get_contents($filename);

        return (false === $res ? null : ($unserialize ? unserialize($res) : $res));
    }

    /**
     * @param string                           $expectedFilename
     * @param string|array|bool|int|float|null $data
     * @param int                              $flags
     *
     * @throws InvalidPathException
     *
     * @return bool
     */
    public function set(string $expectedFilename, $data, int $flags = 0): bool
    {
        if (!is_string($data)) {
            $data = serialize($data);
        }
        $filename = $this->pathBuilder->getAbsolutePathFromArray([$this->getDirectory(), $expectedFilename], true);
        $res      = file_put_contents($filename, $data, $flags);

        return !(false === $res);
    }


    private function getPath(string $filename)
    {

    }

    /**
     * @return string
     */
    public function getDirectory(): string
    {
        return $this->path;
    }
}