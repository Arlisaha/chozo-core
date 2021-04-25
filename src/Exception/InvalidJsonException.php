<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Exception;

use LogicException;
use Throwable;
use function sprintf;

class InvalidJsonException extends LogicException
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
            sprintf('File in path "%s" does not contain valid json.', $path),
            $code,
            $previous
        );
    }
}