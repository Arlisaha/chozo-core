<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Exception;

use Arlisaha\Chozo\Route\RouteElementInterface;
use LogicException;
use Throwable;
use function get_class;
use function gettype;
use function is_object;
use function sprintf;

class InvalidRouteElementException extends LogicException
{
    /**
     * InvalidRouteElementException constructor.
     *
     * @param mixed          $element
     * @param int            $code
     * @param Throwable|null $previous
     */
    public function __construct($element, $code = 0, Throwable $previous = null)
    {
        $message = sprintf(
            'Expected a "%s", "%s" given.',
            RouteElementInterface::class,
            (is_object($element) ? get_class($element) : gettype($element))
        );

        parent::__construct($message, $code, $previous);
    }
}