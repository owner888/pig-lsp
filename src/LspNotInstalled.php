<?php

declare(strict_types=1);

namespace PigLsp;

/**
 * No server is installed for this language: the one case an `Installer` can do something about,
 * so it carries which server it was. The message is the install hint, for when nobody can.
 */
final class LspNotInstalled extends LspUnavailable
{
    public function __construct(public readonly string $key, string $hint)
    {
        parent::__construct($hint);
    }
}
