<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Exception;

use LogicException;
use Throwable;
use function sprintf;

class NoComposerFileFoundException extends LogicException
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
            sprintf('No composer file found in path "%s".', $path),
            $code,
            $previous
        );
    }
}