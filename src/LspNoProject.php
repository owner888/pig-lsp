<?php

declare(strict_types=1);

namespace PigLsp;

/**
 * The file is in no project a language server could index — no root marker of its language and
 * no git repository above it — so none is started for it, and the model is sent back to grep.
 */
final class LspNoProject extends LspUnavailable
{
}
