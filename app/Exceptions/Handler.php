<?php

namespace Pterodactyl\Exceptions;

use Exception;
use PDOException;
use Psr\Log\LoggerInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Pterodactyl\Exceptions\Repository\RecordNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        AuthenticationException::class,
        AuthorizationException::class,
        DisplayException::class,
        HttpException::class,
        ModelNotFoundException::class,
        RecordNotFoundException::class,
        TokenMismatchException::class,
        ValidationException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'token',
        'secret',
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception. Skips Laravel's internal reporter since we
     * don't need or want the user information in our logs by default.
     *
     * If you want to implement logging in a different format to integrate with
     * services such as AWS Cloudwatch or other monitoring you can replace the
     * contents of this function with a call to the parent reporter.
     *
     * @param \Exception $exception
     * @return mixed
     *
     * @throws \Exception
     */
    public function report(Exception $exception)
    {
        if (! config('app.exceptions.report_all', false) && $this->shouldntReport($exception)) {
            return null;
        }

        if (method_exists($exception, 'report')) {
            return $exception->report();
        }

        try {
            $logger = $this->container->make(LoggerInterface::class);
        } catch (Exception $ex) {
            throw $exception;
        }

        return $logger->error($exception instanceof PDOException ? $exception->getMessage() : $exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Exception               $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Exception
     */
    public function render($request, Exception $exception)
    {
        return parent::render($request, $exception);
    }

    /**
     * Transform a validation exception into a consistent format to be returned for
     * calls to the API.
     *
     * @param \Illuminate\Http\Request                   $request
     * @param \Illuminate\Validation\ValidationException $exception
     * @return \Illuminate\Http\JsonResponse
     */
    public function invalidJson($request, ValidationException $exception)
    {
        $codes = collect($exception->validator->failed())->mapWithKeys(function ($reasons, $field) {
            $cleaned = [];
            foreach ($reasons as $reason => $attrs) {
                $cleaned[] = snake_case($reason);
            }

            return [str_replace('.', '_', $field) => $cleaned];
        })->toArray();

        $errors = collect($exception->errors())->map(function ($errors, $field) use ($codes) {
            $response = [];
            foreach ($errors as $key => $error) {
                $response[] = [
                    'code' => array_get($codes, str_replace('.', '_', $field) . '.' . $key),
                    'detail' => $error,
                    'source' => ['field' => $field],
                ];
            }

            return $response;
        })->flatMap(function ($errors) {
            return $errors;
        })->toArray();

        return response()->json(['errors' => $errors], $exception->status);
    }

    /**
     * Return the exception as a JSONAPI representation for use on API requests.
     *
     * @param \Exception $exception
     * @param array      $override
     * @return array
     */
    public static function convertToArray(Exception $exception, array $override = []): array
    {
        $error = [
            'code' => class_basename($exception),
            'status' => method_exists($exception, 'getStatusCode') ? strval($exception->getStatusCode()) : '500',
            'detail' => 'An error was encountered while processing this request.',
        ];

        if (config('app.debug')) {
            $error = array_merge($error, [
                'detail' => $exception->getMessage(),
                'source' => [
                    'line' => $exception->getLine(),
                    'file' => str_replace(base_path(), '', $exception->getFile()),
                ],
                'meta' => [
                    'trace' => explode("\n", $exception->getTraceAsString()),
                ],
            ]);
        }

        return ['errors' => [array_merge($error, $override)]];
    }

    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * @param \Illuminate\Http\Request                 $request
     * @param \Illuminate\Auth\AuthenticationException $exception
     * @return \Illuminate\Http\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        return redirect()->guest(route('auth.login'));
    }

    /**
     * Converts an exception into an array to render in the response. Overrides
     * Laravel's built-in converter to output as a JSONAPI spec compliant object.
     *
     * @param \Exception $exception
     * @return array
     */
    protected function convertExceptionToArray(Exception $exception)
    {
        return self::convertToArray($exception);
    }
}
