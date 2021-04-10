<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Exception;

use LogicException;
use Throwable;

class ConfigFileException extends LogicException
{
    public function __construct($message = 'Missing "settings.yml" or "parameters.yml" config file(s).', $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}