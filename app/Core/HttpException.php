<?php
declare(strict_types=1);

namespace Core;

class HttpException extends \RuntimeException
{
    public function __construct(private int $statusCode, string $message = '')
    {
        parent::__construct($message === '' ? 'Request failed.' : $message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
