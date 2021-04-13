<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\PathBuilder;

use Arlisaha\Chozo\Exception\InvalidPathException;
use function implode;
use function in_array;
use function mkdir;
use function realpath;
use const DIRECTORY_SEPARATOR;

class PathBuilder
{
    protected const PERMISSION = 0744;
    protected const SEPARATORS = [DIRECTORY_SEPARATOR, '/', '\\'];

    /**
     * @var string
     */
    private $rootDir;

    /**
     * PathBuilder constructor.
     *
     * @param string $rootDir
     *
     * @throws InvalidPathException
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
        return $this->rootDir ?? '';
    }

    /**
     * @param string $relativePath
     * @param bool   $create
     * @param int    $permissions
     *
     * @throws InvalidPathException
     *
     * @return string
     */
    public function getAbsolutePath(string $relativePath, bool $create = false, int $permissions = self::PERMISSION): string
    {
        return $this->getRtrimmedRealPath($relativePath, $create, $permissions);
    }

    /**
     * @param array $relativePathParts
     * @param bool  $create
     * @param int   $permissions
     *
     * @throws InvalidPathException
     *
     * @return string
     */
    public function getAbsolutePathFromArray(array $relativePathParts, bool $create = false, int $permissions = self::PERMISSION): string
    {
        return $this->getAbsolutePath(implode(DIRECTORY_SEPARATOR, $relativePathParts), $create, $permissions);
    }

    /**
     * @param string $path
     * @param int    $permissions
     * @param bool   $recursive
     *
     * @return bool
     */
    public function createPath(string $path, int $permissions = self::PERMISSION, bool $recursive = true): bool
    {
        return mkdir(
            $this->getRootDir() . $path,
            $permissions,
            $recursive
        );
    }

    /**
     * @param string $path
     * @param bool   $create
     * @param int    $permissions
     *
     * @throws InvalidPathException
     *
     * @return string
     */
    protected function getRtrimmedRealPath(string $path, bool $create = false, int $permissions = self::PERMISSION): string
    {
        if($path === realpath($path)) {
            return rtrim($path, implode('', static::SEPARATORS));
        }

        if (!in_array($path[0], static::SEPARATORS, true)) {
            $path = DIRECTORY_SEPARATOR . $path;
        }

        $origPath = $path;
        if (false === ($path = realpath($this->getRootDir() . $origPath))) {
            if (!$create || !$this->createPath($origPath, $permissions)) {
                throw new InvalidPathException($origPath);
            }

            return $this->getRtrimmedRealPath($origPath, $create, $permissions);
        }

        return rtrim($path, implode('', static::SEPARATORS));
    }
}