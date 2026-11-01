<?php

declare(strict_types=1);

namespace PigLsp;

use RuntimeException;

/** A request the server answered with an error, or could not answer because it is gone. */
class LspError extends RuntimeException
{
    /** The JSON-RPC error code, when the server sent one (-32601 method not found, …). */
    public function __construct(string $message, public readonly ?int $rpcCode = null)
    {
        parent::__construct($message);
    }
}
