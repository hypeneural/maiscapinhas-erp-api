<?php

declare(strict_types=1);

namespace App\Services\Wheel;

use Exception;

/**
 * Exception para erros no processo de giro.
 */
class SpinException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $code = 'SPIN_ERROR',
        ?Exception $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function toArray(): array
    {
        return [
            'error' => true,
            'code' => $this->code,
            'message' => $this->getMessage(),
        ];
    }
}
