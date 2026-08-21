<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class PeriodLifecycleException extends HttpException
{
    public static function conflict(string $message): self
    {
        return new self(409, $message);
    }

    public static function invalidTransition(string $message): self
    {
        return new self(422, $message);
    }
}
