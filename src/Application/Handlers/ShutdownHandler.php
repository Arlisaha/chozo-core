<?php
declare(strict_types=1);

namespace Arlisaha\Chozo\Application\Handlers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\ResponseEmitter;
use function error_get_last;
use function sprintf;

class ShutdownHandler
{
    /**
     * @var Request
     */
    private $request;

    /**
     * @var callable
     */
    private $errorHandler;

    /**
     * @var bool
     */
    private $displayErrorDetails;

    /**
     * @var bool
     */
    private $logErrors;

    /**
     * @var bool
     */
    private $logErrorDetails;

    /**
     * @var ResponseEmitter
     */
    private $responseEmitter;

    /**
     * ShutdownHandler constructor.
     *
     * @param Request         $request
     * @param callable        $errorHandler
     * @param ResponseEmitter $responseEmitter
     * @param bool            $displayErrorDetails
     * @param bool            $logErrors
     * @param bool            $logErrorDetails
     */
    public function __construct(Request $request, callable $errorHandler, ResponseEmitter $responseEmitter, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails)
    {
        $this->request             = $request;
        $this->errorHandler        = $errorHandler;
        $this->displayErrorDetails = $displayErrorDetails;
        $this->logErrors           = $logErrors;
        $this->logErrorDetails     = $logErrorDetails;
        $this->responseEmitter     = $responseEmitter;
    }

    public function __invoke()
    {
        $error = error_get_last();
        if ($error) {
            $errorFile    = $error['file'];
            $errorLine    = $error['line'];
            $errorMessage = $error['message'];
            $errorType    = $error['type'];
            $message      = 'An error while processing your request. Please try again later.';

            if ($this->displayErrorDetails) {
                $message = '';
                switch ($errorType) {
                    case E_USER_WARNING:
                        $message .= sprintf('WARNING: %s.', $errorMessage);
                        break;
                    case E_USER_NOTICE:
                        $message .= sprintf('NOTICE: %s.', $errorMessage);
                        break;
                    case E_USER_ERROR:
                        $message = 'FATAL ';
                    default:
                        $message .= sprintf(
                            'ERROR: "%s" on line %s in file %s.',
                            $errorMessage,
                            $errorLine,
                            $errorFile
                        );
                }
            }

            $exception = new HttpInternalServerErrorException($this->request, $message);
            $response  = ($this->errorHandler)(
                $this->request,
                $exception,
                $this->displayErrorDetails,
                $this->logErrors,
                $this->logErrorDetails
            );

            ($this->responseEmitter)->emit($response);
        }
    }
}
