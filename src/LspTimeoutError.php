<?php

declare(strict_types=1);

namespace PigLsp;

/**
 * A request the server did not answer in time.
 *
 * Upstream had no timeouts: a server stuck indexing left the tool call, and the turn, waiting for
 * ever. Here the request is cancelled (`$/cancelRequest`) and the model is told to try again.
 */
final class LspTimeoutError extends LspError
{
}
