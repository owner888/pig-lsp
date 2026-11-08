<?php

declare(strict_types=1);

use Pig\CodingAgent\Extensions\ExtensionApi;
use PigLsp\Extension;

/*
 * pig-lsp — language-server tools for pig: definition, references, implementation, hover,
 * symbols, diagnostics, rename preview and code actions, plus errors appended to edits.
 *
 * A package installed from git is not run through Composer, so nothing autoloads its classes;
 * this registers the loader for the package's own namespace, once.
 */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PigLsp\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

return static function (ExtensionApi $pig): void {
    Extension::register($pig);
};
