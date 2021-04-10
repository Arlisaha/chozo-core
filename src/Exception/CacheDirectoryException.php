<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Exception;

use Exception;
use Throwable;

class CacheDirectoryException extends Exception
{
    public function __construct($message = 'Unable to create cache directory.', $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}