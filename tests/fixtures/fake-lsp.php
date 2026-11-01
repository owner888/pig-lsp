<?php

declare(strict_types=1);

/*
 * A language server small enough to reason about, for the tests — upstream's fake-lsp.mjs, grown
 * to answer every request the tools make, with answers built so a test can check what was asked:
 *
 * - definition answers the very position it was asked about (so column conversion is visible);
 * - references answers that position and the start of the file;
 * - implementation answers every `class X implements <word>` line when asked on <word>;
 * - documentSymbol outlines `class X` / `interface X` / `function f` lines;
 * - every line containing ERROR (or WARN) is an error (or warning) diagnostic, published on
 *   open, change and save;
 * - rename replaces the word under the cursor in every open document.
 *
 * Behaviour switches, by environment: FAKE_LSP_LOG (file: every message received, one JSON per
 * line), FAKE_LSP_ENCODING (utf-8 to choose it), FAKE_LSP_HANG_ON (a method never answered),
 * FAKE_LSP_CRASH_ON (a method that makes it exit 3), FAKE_LSP_DIAG_DELAY (ms before publishing),
 * FAKE_LSP_NO_IMPLEMENTATION, FAKE_LSP_START_DELAY (ms before answering initialize),
 * FAKE_LSP_FAIL_START (exit 2 at once, after a line on stderr), FAKE_LSP_TWO_STEP (ms between an
 * empty publish and the real one).
 */

$env = static fn (string $name): string => (string) (getenv($name) ?: '');

if ($env('FAKE_LSP_FAIL_START') !== '') {
    fwrite(STDERR, "fake-lsp: refusing to start\n");
    exit(2);
}

$log = $env('FAKE_LSP_LOG');
$encoding = $env('FAKE_LSP_ENCODING') === 'utf-8' ? 'utf-8' : 'utf-16';
$documents = [];
$nextId = 1000;

$send = static function (array $message): void {
    $body = json_encode(['jsonrpc' => '2.0', ...$message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite(STDOUT, 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
    fflush(STDOUT);
};

$read = static function (): ?array {
    $length = null;

    while (true) {
        $line = fgets(STDIN);

        if ($line === false) {
            return null;
        }

        $line = rtrim($line, "\r\n");

        if ($line === '') {
            break;
        }

        if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $m) === 1) {
            $length = (int) $m[1];
        }
    }

    $body = '';

    while (strlen($body) < (int) $length) {
        $chunk = fread(STDIN, (int) $length - strlen($body));

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $body .= $chunk;
    }

    return json_decode($body, true);
};

/** A code-point column as the negotiated encoding counts it. */
$column = static function (string $line, int $points) use ($encoding): int {
    $prefix = mb_substr($line, 0, $points, 'UTF-8');

    return $encoding === 'utf-8' ? strlen($prefix) : intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2);
};

/** The encoding's column back to code points. */
$points = static function (string $line, int $column) use ($encoding): int {
    if ($encoding === 'utf-8') {
        return mb_strlen(substr($line, 0, $column), 'UTF-8');
    }

    $units = 0;
    $count = 0;

    foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
        if ($units >= $column) {
            break;
        }

        $units += mb_ord($char, 'UTF-8') > 0xFFFF ? 2 : 1;
        $count++;
    }

    return $count;
};

$lines = static fn (string $text): array => explode("\n", str_replace("\r\n", "\n", $text));

$wordAt = static function (string $line, int $pointColumn): string {
    $chars = mb_str_split($line, 1, 'UTF-8');
    $isWord = static fn (?string $c): bool => $c !== null && preg_match('/^[\p{L}\p{N}_$]$/u', $c) === 1;
    $start = $pointColumn;
    $end = $pointColumn;

    while ($start > 0 && $isWord($chars[$start - 1] ?? null)) {
        $start--;
    }

    while ($isWord($chars[$end] ?? null)) {
        $end++;
    }

    return implode('', array_slice($chars, $start, $end - $start));
};

$publish = static function (string $uri) use (&$documents, $send, $lines, $column, $env): void {
    // phpactor's way: clear at once, the findings a little later.
    $twoStep = (int) $env('FAKE_LSP_TWO_STEP');

    if ($twoStep > 0) {
        $send(['method' => 'textDocument/publishDiagnostics', 'params' => ['uri' => $uri, 'diagnostics' => []]]);
        usleep($twoStep * 1000);
    }

    $delay = (int) $env('FAKE_LSP_DIAG_DELAY');

    if ($delay > 0) {
        usleep($delay * 1000);
    }

    $diagnostics = [];

    foreach ($lines($documents[$uri]['text'] ?? '') as $index => $line) {
        foreach (['ERROR' => 1, 'WARN' => 2] as $word => $severity) {
            $at = mb_strpos($line, $word, 0, 'UTF-8');

            if ($at !== false) {
                $diagnostics[] = [
                    'range' => ['start' => ['line' => $index, 'character' => $column($line, $at)], 'end' => ['line' => $index, 'character' => $column($line, $at + strlen($word))]],
                    'severity' => $severity,
                    'source' => 'fake',
                    'message' => "found {$word}",
                ];
            }
        }
    }

    $send(['method' => 'textDocument/publishDiagnostics', 'params' => ['uri' => $uri, 'version' => $documents[$uri]['version'] ?? 0, 'diagnostics' => $diagnostics]]);
};

while (($message = $read()) !== null) {
    if ($log !== '') {
        file_put_contents($log, json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }

    $method = $message['method'] ?? null;
    $id = $message['id'] ?? null;
    $params = $message['params'] ?? [];

    if ($method === null) {
        continue; // a response to one of our requests; the log has it
    }

    if ($method === $env('FAKE_LSP_CRASH_ON')) {
        fwrite(STDERR, "fake-lsp: crashing on {$method}\n");
        exit(3);
    }

    if ($method === $env('FAKE_LSP_HANG_ON') && $id !== null) {
        continue;
    }

    $uri = $params['textDocument']['uri'] ?? null;
    $position = $params['position'] ?? null;
    $text = is_string($uri) ? ($documents[$uri]['text'] ?? '') : '';
    $lineText = is_array($position) ? ($lines($text)[$position['line']] ?? '') : '';
    $word = is_array($position) ? $wordAt($lineText, $points($lineText, (int) $position['character'])) : '';

    switch ($method) {
        case 'initialize':
            if ((int) $env('FAKE_LSP_START_DELAY') > 0) {
                usleep((int) $env('FAKE_LSP_START_DELAY') * 1000);
            }

            $capabilities = [
                'positionEncoding' => $encoding,
                'textDocumentSync' => ['openClose' => true, 'change' => 1, 'save' => ['includeText' => false]],
                'hoverProvider' => true,
                'definitionProvider' => true,
                'referencesProvider' => true,
                'implementationProvider' => true,
                'documentSymbolProvider' => true,
                'workspaceSymbolProvider' => true,
                'renameProvider' => ['prepareProvider' => true],
                'codeActionProvider' => true,
            ];

            if ($env('FAKE_LSP_NO_IMPLEMENTATION') !== '') {
                unset($capabilities['implementationProvider']);
            }

            $send(['id' => $id, 'result' => ['capabilities' => $capabilities, 'serverInfo' => ['name' => 'fake-lsp', 'version' => '1.0']]]);

            break;

        case 'initialized':
            // What real servers do right after the handshake: ask for settings, make a progress token.
            $send(['id' => $nextId++, 'method' => 'workspace/configuration', 'params' => ['items' => [['section' => 'fake']]]]);
            $send(['id' => $nextId++, 'method' => 'window/workDoneProgress/create', 'params' => ['token' => 'index']]);
            $send(['id' => $nextId++, 'method' => 'fake/unknownRequest', 'params' => []]);

            break;

        case 'textDocument/didOpen':
            $documents[$params['textDocument']['uri']] = ['text' => $params['textDocument']['text'], 'version' => $params['textDocument']['version']];
            $publish($params['textDocument']['uri']);

            break;

        case 'textDocument/didChange':
            $documents[$uri] = ['text' => end($params['contentChanges'])['text'], 'version' => $params['textDocument']['version']];
            $publish($uri);

            break;

        case 'textDocument/didSave':
            $publish($uri);

            break;

        case 'textDocument/didClose':
            unset($documents[$uri]);

            break;

        case 'textDocument/hover':
            $send(['id' => $id, 'result' => ['contents' => ['kind' => 'markdown', 'value' => "fake hover {$position['line']}:{$position['character']} `{$word}`"]]]);

            break;

        case 'textDocument/definition':
            $send(['id' => $id, 'result' => ['uri' => $uri, 'range' => ['start' => $position, 'end' => $position]]]);

            break;

        case 'textDocument/references':
            $result = [['uri' => $uri, 'range' => ['start' => $position, 'end' => $position]], ['uri' => $uri, 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]]]];

            if (!empty($params['context']['includeDeclaration'])) {
                $result[] = ['uri' => $uri, 'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]]];
            }

            $send(['id' => $id, 'result' => $result]);

            break;

        case 'textDocument/implementation':
            $result = [];

            foreach ($documents as $docUri => $document) {
                foreach ($lines($document['text']) as $index => $line) {
                    if ($word !== '' && preg_match('/class\s+(\w+)\s+implements\s+[\w, ]*\b' . preg_quote($word, '/') . '\b/', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
                        $at = mb_strlen(substr($line, 0, $m[1][1]), 'UTF-8');
                        $result[] = ['targetUri' => $docUri, 'targetRange' => ['start' => ['line' => $index, 'character' => 0], 'end' => ['line' => $index, 'character' => 0]], 'targetSelectionRange' => ['start' => ['line' => $index, 'character' => $column($line, $at)], 'end' => ['line' => $index, 'character' => $column($line, $at)]]];
                    }
                }
            }

            $send(['id' => $id, 'result' => $result]);

            break;

        case 'textDocument/documentSymbol':
            $symbols = [];
            $currentClass = null;

            foreach ($lines($text) as $index => $line) {
                if (preg_match('/(class|interface)\s+(\w+)/', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
                    $at = mb_strlen(substr($line, 0, $m[2][1]), 'UTF-8');
                    $range = ['start' => ['line' => $index, 'character' => 0], 'end' => ['line' => $index, 'character' => $column($line, mb_strlen($line))]];
                    $symbols[] = ['name' => $m[2][0], 'kind' => $m[1][0] === 'class' ? 5 : 11, 'range' => $range, 'selectionRange' => ['start' => ['line' => $index, 'character' => $column($line, $at)], 'end' => ['line' => $index, 'character' => $column($line, $at + strlen($m[2][0]))]], 'children' => []];
                    $currentClass = array_key_last($symbols);
                } elseif (preg_match('/function\s+(\w+)/', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
                    $at = mb_strlen(substr($line, 0, $m[1][1]), 'UTF-8');
                    $symbol = ['name' => $m[1][0], 'kind' => $currentClass !== null ? 6 : 12, 'range' => ['start' => ['line' => $index, 'character' => 0], 'end' => ['line' => $index, 'character' => 0]], 'selectionRange' => ['start' => ['line' => $index, 'character' => $column($line, $at)], 'end' => ['line' => $index, 'character' => $column($line, $at)]]];

                    if ($currentClass !== null) {
                        $symbols[$currentClass]['children'][] = $symbol;
                    } else {
                        $symbols[] = $symbol;
                    }
                }
            }

            $send(['id' => $id, 'result' => $symbols]);

            break;

        case 'workspace/symbol':
            $result = [];

            foreach ($documents as $docUri => $document) {
                foreach ($lines($document['text']) as $index => $line) {
                    if (preg_match('/(class|interface|function)\s+(\w*' . preg_quote((string) $params['query'], '/') . '\w*)/i', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
                        $at = mb_strlen(substr($line, 0, $m[2][1]), 'UTF-8');
                        $result[] = ['name' => $m[2][0], 'kind' => $m[1][0] === 'function' ? 12 : 5, 'location' => ['uri' => $docUri, 'range' => ['start' => ['line' => $index, 'character' => $column($line, $at)], 'end' => ['line' => $index, 'character' => $column($line, $at)]]]];
                    }
                }
            }

            $send(['id' => $id, 'result' => $result]);

            break;

        case 'textDocument/prepareRename':
            $send(['id' => $id, 'result' => $word === '' ? null : ['start' => $position, 'end' => $position]]);

            break;

        case 'textDocument/rename':
            $changes = [];

            foreach ($documents as $docUri => $document) {
                foreach ($lines($document['text']) as $index => $line) {
                    if ($word !== '' && preg_match_all('/\b' . preg_quote($word, '/') . '\b/u', $line, $all, PREG_OFFSET_CAPTURE) > 0) {
                        foreach ($all[0] as $match) {
                            $at = mb_strlen(substr($line, 0, $match[1]), 'UTF-8');
                            $changes[$docUri][] = ['range' => ['start' => ['line' => $index, 'character' => $column($line, $at)], 'end' => ['line' => $index, 'character' => $column($line, $at + mb_strlen($word))]], 'newText' => $params['newName']];
                        }
                    }
                }
            }

            $send(['id' => $id, 'result' => ['changes' => $changes]]);

            break;

        case 'textDocument/codeAction':
            $send(['id' => $id, 'result' => [
                ['title' => 'Fix the ERROR', 'kind' => 'quickfix', 'isPreferred' => true, 'diagnostics' => $params['context']['diagnostics'] ?? [], 'edit' => ['changes' => [$uri => [['range' => $params['range'], 'newText' => 'fixed']]]]],
                ['title' => 'Run a server command', 'kind' => 'refactor', 'command' => ['title' => 'x', 'command' => 'fake.command']],
            ]]);

            break;

        case 'shutdown':
            $send(['id' => $id, 'result' => null]);

            break;

        case 'exit':
            exit(0);

        default:
            if ($id !== null) {
                $send(['id' => $id, 'error' => ['code' => -32601, 'message' => "unhandled {$method}"]]);
            }
    }
}
