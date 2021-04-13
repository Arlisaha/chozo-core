<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Exception;

use Exception;
use Throwable;
use function sprintf;

class InvalidPathException extends Exception
{
    /**
     * InvalidPathException constructor.
     * @param string         $path
     * @param int            $code
     * @param Throwable|null $previous
     */
    public function __construct(string $path, $code = 0, Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Invalid path "%s".', $path),
            $code,
            $previous
        );
    }
}