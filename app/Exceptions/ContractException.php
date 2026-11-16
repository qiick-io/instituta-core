<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ContractErrorCodeEnum;
use InvalidArgumentException;

/**
 * A refused contract request with its stable machine code (#227). Extends
 * InvalidArgumentException so the callers that already turn refusals into 422 / tool errors keep
 * working; they now also read {@see self::$errorCode}.
 */
final class ContractException extends InvalidArgumentException
{
    public function __construct(
        public readonly ContractErrorCodeEnum $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The code of any refusal: the contract code when there is one, `invalid_change` otherwise.
     */
    public static function codeOf(\Throwable $exception): ContractErrorCodeEnum
    {
        return $exception instanceof self ? $exception->errorCode : ContractErrorCodeEnum::InvalidChange;
    }
}
