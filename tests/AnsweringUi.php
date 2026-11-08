<?php

declare(strict_types=1);

namespace PigLsp\Test;

use Closure;
use Pig\CodingAgent\Extensions\ExtensionUi;
use Pig\CodingAgent\Theme\Theme;
use Pig\CodingAgent\Theme\Themes;

/**
 * A `ExtensionUi` that answers confirmations as told and writes down what it was asked and told — the
 * user in front of the install question. pig's own test UI says no to everything, and is final.
 */
final class AnsweringUi implements ExtensionUi
{
    /** @var list<bool> what the next confirmations answer, in order; no once they run out */
    public array $answers = [];

    /** How long the user takes to answer: long enough for other calls to arrive meanwhile. */
    public float $thinking = 0.0;

    /** @var list<array{title: string, message: string}> */
    public array $prompts = [];

    /** @var list<string> `key: text`, `key: (cleared)` */
    public array $statuses = [];

    /** @var list<string> `[level] message` */
    public array $notices = [];

    #[\Override]
    public function select(string $title, array $options, ?int $timeout = null): ?string
    {
        return null;
    }

    #[\Override]
    public function confirm(string $title, string $message, ?int $timeout = null): bool
    {
        $this->prompts[] = ['title' => $title, 'message' => $message];

        if ($this->thinking > 0) {
            \Pig\Async\Async::delay($this->thinking);
        }

        return array_shift($this->answers) ?? false;
    }

    #[\Override]
    public function input(string $title, string $placeholder = '', ?\Pig\Async\AbortSignal $signal = null, ?int $timeout = null): ?string
    {
        return null;
    }

    #[\Override]
    public function editor(string $title, string $prefill = ''): ?string
    {
        return null;
    }

    #[\Override]
    public function custom(Closure $factory): mixed
    {
        return null;
    }

    #[\Override]
    public function notify(string $message, string $level = 'info'): void
    {
        $this->notices[] = "[{$level}] {$message}";
    }

    #[\Override]
    public function systemNotify(string $title, string $body = ''): void
    {
        $this->notices[] = "[system] {$title}: {$body}";
    }

    #[\Override]
    public function setStatus(string $key, ?string $text): void
    {
        $this->statuses[] = $key . ': ' . ($text ?? '(cleared)');
    }

    #[\Override]
    public function setEditorText(string $text): void
    {
    }

    #[\Override]
    public function getEditorText(): string
    {
        return '';
    }

    #[\Override]
    public function theme(): Theme
    {
        return Themes::theme();
    }

    #[\Override]
    public function pasteToEditor(string $text): void
    {
    }

    #[\Override]
    public function setTitle(string $title): void
    {
    }

    #[\Override]
    public function setWorkingMessage(?string $message = null): void
    {
    }

    #[\Override]
    public function setWorkingVisible(bool $visible): void
    {
    }

    #[\Override]
    public function setWorkingIndicator(?array $options = null): void
    {
    }

    #[\Override]
    public function setHiddenThinkingLabel(?string $label = null): void
    {
    }

    #[\Override]
    public function getToolsExpanded(): bool
    {
        return false;
    }

    #[\Override]
    public function setToolsExpanded(bool $expanded): void
    {
    }

    #[\Override]
    public function setWidget(string $key, array|Closure|null $content, array $options = []): void
    {
    }

    #[\Override]
    public function setHeader(?Closure $factory): void
    {
    }

    #[\Override]
    public function setFooter(?Closure $factory): void
    {
    }

    #[\Override]
    public function getAllThemes(): array
    {
        return Themes::getAvailableThemesWithPaths();
    }

    #[\Override]
    public function getTheme(string $name): ?Theme
    {
        return Themes::getThemeByName($name);
    }

    #[\Override]
    public function setTheme(string|Theme $theme): array
    {
        return ['success' => true];
    }

    #[\Override]
    public function onTerminalInput(callable $handler): Closure
    {
        return static fn () => null;
    }

    #[\Override]
    public function addAutocompleteProvider(Closure $factory): void
    {
    }

    #[\Override]
    public function setEditorComponent(?Closure $factory): void
    {
    }

    #[\Override]
    public function getEditorComponent(): ?Closure
    {
        return null;
    }
}
