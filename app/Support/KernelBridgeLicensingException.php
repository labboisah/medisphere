<?php

namespace App\Support;

use RuntimeException;

class KernelBridgeLicensingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
