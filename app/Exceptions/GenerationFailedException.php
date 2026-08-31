<?php

namespace App\Exceptions;

use RuntimeException;

class GenerationFailedException extends RuntimeException
{
    public function __construct(
        string $message = 'Generation failed.',
        public readonly int $status = 502,
    ) {
        parent::__construct($message);
    }
}
