<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Exception;

use LogicException;
use Throwable;

class KernelNotCreatedException extends LogicException
{
    public function __construct($message = 'Kernel has not been initialized yet.', $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}