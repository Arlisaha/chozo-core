<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Exception;

use Arlisaha\Chozo\Application\Equipment\EquipmentInterface;
use LogicException;
use Throwable;
use function get_class;
use function gettype;
use function is_object;
use function sprintf;

class InvalidEquipmentException extends LogicException
{
    /**
     * InvalidEquipmentException constructor.
     *
     * @param mixed          $element
     * @param int            $code
     * @param Throwable|null $previous
     */
    public function __construct($element, $code = 0, Throwable $previous = null)
    {
        $message = sprintf(
            'Expected a "%s", "%s" given.',
            EquipmentInterface::class,
            (is_object($element) ? get_class($element) : gettype($element))
        );

        parent::__construct($message, $code, $previous);
    }
}