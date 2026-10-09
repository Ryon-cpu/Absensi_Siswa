<?php

namespace App\Exceptions;

use RuntimeException;

class WhatsAppApiException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}
