<?php

declare(strict_types=1);

namespace PigLsp;

use JsonException;

/**
 * LSP's base protocol: `Content-Length: N\r\n\r\n` then N bytes of JSON.
 *
 * Incremental, because a pipe hands over whatever it has — half a header, three messages and the
 * start of a fourth. The length counts bytes, which in PHP is what `strlen()` counts, so no
 * re-encoding is needed either way. A header block without a usable length is skipped rather than
 * fatal: one malformed message from a server should cost that message, not the connection.
 */
final class Framing
{
    /** Larger than any sane LSP message; a server that sends more is broken, and memory is finite. */
    public const int MAX_MESSAGE_BYTES = 64 * 1024 * 1024;

    private string $buffer = '';

    /** Errors met while parsing (bad JSON, bad headers), for whoever wants to log them. */
    private array $errors = [];

    /** @param array<string, mixed> $message */
    public static function encode(array $message): string
    {
        $body = json_encode(
            $message,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        return 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
    }

    /**
     * Feed bytes in; get back every message they completed.
     *
     * @return list<array<string, mixed>>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $messages = [];

        while (true) {
            $end = strpos($this->buffer, "\r\n\r\n");

            if ($end === false) {
                // A header never runs this long; whatever this is, it is not going to become one.
                if (strlen($this->buffer) > 8192 && !str_contains($this->buffer, "\r\n")) {
                    $this->errors[] = 'Dropped ' . strlen($this->buffer) . ' bytes with no LSP header';
                    $this->buffer = '';
                }

                break;
            }

            $length = null;

            foreach (explode("\r\n", substr($this->buffer, 0, $end)) as $header) {
                if (preg_match('/^Content-Length:\s*(\d+)\s*$/i', $header, $match) === 1) {
                    $length = (int) $match[1];
                }
            }

            if ($length === null || $length > self::MAX_MESSAGE_BYTES) {
                $this->errors[] = $length === null ? 'LSP header without Content-Length' : "LSP message of {$length} bytes is too large";
                $this->buffer = (string) substr($this->buffer, $end + 4);

                continue;
            }

            if (strlen($this->buffer) < $end + 4 + $length) {
                break;
            }

            $body = substr($this->buffer, $end + 4, $length);
            $this->buffer = (string) substr($this->buffer, $end + 4 + $length);

            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                $this->errors[] = 'Invalid JSON from the server: ' . $error->getMessage();

                continue;
            }

            if (is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    /** Bytes waiting for the rest of their message. */
    public function pending(): int
    {
        return strlen($this->buffer);
    }

    /** @return list<string> the parse errors since the last call */
    public function takeErrors(): array
    {
        $errors = $this->errors;
        $this->errors = [];

        return $errors;
    }
}
