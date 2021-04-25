<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Exception;

use LogicException;
use Throwable;

class NoPsr4AutoloadKeyFound extends LogicException
{
    /**
     * InvalidPathException constructor.
     *
     * @param int            $code
     * @param Throwable|null $previous
     */
    public function __construct($code = 0, Throwable $previous = null)
    {
        parent::__construct(
            'No "autoload" or "psr-4" key found in json',
            $code,
            $previous
        );
    }
}