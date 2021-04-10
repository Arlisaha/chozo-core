<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Exception;

use LogicException;
use Throwable;

class MissingConfigKeyException extends LogicException
{
    /**
     * MissingConfigKeyException constructor.
     * @param string         $key
     * @param string|null    $parentKey
     * @param int            $code
     * @param Throwable|null $previous
     */
    public function __construct(string $key, ?string $parentKey = null, $code = 0, Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Missing config key "%s"%s.', $key, ($parentKey ? sprintf(' with parent "%s"', $parentKey) : '')),
            $code,
            $previous
        );
    }
}