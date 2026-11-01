# pig-lsp

Language-server tools for the pig coding agent. grep finds text; these find **symbols** — a definition is not confused with a call, `Order::pay()` is not confused with `Invoice::pay()`, and a comment that mentions a name is not a reference.

[中文](README.zh-CN.md)

## Installation

```bash
pig install git:github.com/owner888/pig-lsp
```

Then install a language server for each language you work in (below), or let pig-lsp offer to when it first needs one. Nothing else: the package has no Composer dependencies.

## Tools

| Tool | What it answers |
| --- | --- |
| `lsp_definition` | Where does this call go? |
| `lsp_references` | Who uses this method / class / function? |
| `lsp_implementation` | Which classes implement this interface, which methods implement this interface method? |
| `lsp_hover` | Type and documentation at a position |
| `lsp_symbols` | A file's outline (`path`), or a workspace-wide search (`query`) |
| `lsp_diagnostics` | Errors and warnings for a file, or for all checked files (`"*"`) |
| `lsp_rename` | A preview of a rename across the project — nothing is written |
| `lsp_code_actions` | Quick fixes and refactorings on offer, with their edits — nothing is applied |

A position is given as **`path` + `line` + `query`** (the name as written on that line); the column is found for you. `query` alone finds the declaration of that name in the file. `line` + `character` also works. Lines and columns are 1-based, columns count characters (not bytes).

Every location comes back as `path:line:col` followed by the text of that line, grouped by file, project files before `vendor/` and `node_modules/`:

```
2 implementations of `charge` in 2 files:
src/Pay/AlipayPay.php:7:5  public function charge(int $amount): string

src/Pay/WechatPay.php:7:5  public function charge(int $amount): string
```

### Errors after every edit

While a language's server is running, each `write` and `edit` of one of its files has the server's **errors** for that file appended to the tool result (at most 10 lines):

```
⚠ LSP (php): 1 error in src/PayFactory.php after this change:
src/PayFactory.php:15:29 error: Class "AliPayy" not found [phpactor]
```

The edit waits for the server's answer (up to 3 s), not for a fixed delay. Servers are not started by edits — only by a query — so an edit never waits for one to boot.

### Commands

- `/lsp` — the running servers, their roots, what they are busy with, and why one failed (with the end of its stderr).
- `/lsp-restart [language]` — stop all servers (or one), and re-read the configuration. The next query starts them again.
- `/lsp-install [language]` — install a missing server (asks first, as below). Without a language: the ones this project needs.

### Skill

`lsp-code-navigation` teaches the model when to use which tool, and how to trace a call through an interface to the code that actually runs: `lsp_implementation` → `lsp_references` on the interface (to find the container binding or factory) → read it → say which implementation runs, or that it depends on runtime input.

## Language servers

The first one found is used: on `PATH`, then in the directories installers put programs in that are often not on it (composer's global `vendor/bin`, `~/go/bin`, `~/.cargo/bin`).

| Language | Server | Install |
| --- | --- | --- |
| PHP | `phpactor language-server` | `composer global require phpactor/phpactor`, or phpactor.phar from its releases, saved on PATH as `phpactor` |
| PHP (alternative) | `intelephense --stdio` | `npm install -g intelephense` |
| Dart / Flutter | `dart language-server --protocol=lsp` | the Dart or Flutter SDK |
| TypeScript / JavaScript | `typescript-language-server --stdio` | `npm install -g typescript-language-server typescript` |
| Python | `pyright-langserver --stdio` (or basedpyright, pylsp) | `npm install -g pyright` |
| Rust | `rust-analyzer` | `rustup component add rust-analyzer` |
| Go | `gopls` | `go install golang.org/x/tools/gopls@latest` |
| Java | `jdtls` | `brew install jdtls` |

### When a server is missing

The first time a tool needs a server that is not installed, pig asks you — once per language per session:

```
Install phpactor?
No php language server is installed, so pig falls back to grep for …
pig-lsp can install phpactor by running:
    composer global require phpactor/phpactor
This changes your global composer environment. …
```

- **Yes**: the command runs in the background (status line `LSP: installing phpactor…`, up to 5 minutes, escape stops it), the server is found — on `PATH`, or where the installer says it put it (`composer global config bin-dir`, `go env GOPATH`, `npm prefix -g`) — and the original query goes on. A server found off `PATH` works anyway (it is started with its directory on its `PATH`, which phpactor needs for diagnostics), and the result suggests adding the directory to your `PATH`.
- **No**: not asked again this session; the tools tell the model the server is missing and to use grep.
- **It fails**: the installer's last lines are shown, and it is not tried again this session.
- Calls that arrive while the question is open wait for the same answer: one question, not one per call.
- Only a command from the table above is offered, and only when its installer (composer, npm, rustup, go, brew) is itself installed; intelephense is offered when there is no composer for phpactor, jdtls only on macOS, Dart never (install the SDK). A server you configured yourself (`command`) is yours to install. Otherwise the hint is shown instead.
- Without a UI (`pig -p`, RPC without one) nothing is asked and nothing is installed.

Whatever happened, the model is told not to install a server itself and to fall back to grep, saying its results are textual. `/lsp` shows a missing server as installable, declined or failed; `/lsp-install` asks again.

For PHP, prefer phpactor: it is free and finds implementations of interfaces and interface methods. intelephense's free edition does not offer "go to implementation" (`lsp_implementation` then says so and suggests `lsp_references`). phpactor builds an index of the project in the background after it starts; on a large project the first implementation and reference queries can come back incomplete for a minute (the tools say when the server reports it is still indexing).

## Configuration

Optional. Read from, each overriding the one before:

1. pig's settings, key `lsp` (`~/.pig/agent/settings.json` or the project's settings)
2. `.pi-lsp.json` in the project (pi-lsp-extension's file, read as is)
3. `.pig/lsp.json` in the project

```json
{
  "servers": {
    "php": { "command": "intelephense", "args": ["--stdio"], "initializationOptions": { "storagePath": "/tmp/intelephense" } },
    "dart": { "settings": { "dart": { "completeFunctionCalls": false } } },
    "zig": { "command": "zls", "extensions": [".zig", ".zon"] },
    "java": { "disabled": true }
  },
  "autoInjectDiagnostics": ["php", "dart"],
  "maxDiagnostics": 10,
  "diagnosticsWait": 3000,
  "autoStart": ["php"]
}
```

| Key | Default | Meaning |
| --- | --- | --- |
| `servers.<language>.command`, `args`, `env` | built in | How to start the server |
| `servers.<language>.initializationOptions` | | Sent with `initialize` |
| `servers.<language>.settings` | | Answered to `workspace/configuration`, and sent once after start |
| `servers.<language>.extensions` | | File extensions it serves (for a server that is not built in) |
| `servers.<language>.rootMarkers` | built in | Files that mark a project root (`composer.json`, `pubspec.yaml`, …) |
| `servers.<language>.installers` | built in | What `/lsp-install` may run: `[{"binary": "zls", "with": "brew", "command": ["brew", "install", "zls"]}]` — offered only when `with` is installed, and only for a server found through `candidates` (`[["zls"]]`), not a `command` of yours |
| `servers.<language>.disabled` | `false` | Never start it |
| `servers.<language>.diagnosticsSettle` | `400` | ms the server must stay quiet before an edit's errors are final |
| `autoInjectDiagnostics` (or `autoDiagnostics`) | `true` | `true`, `false`, or a list of languages |
| `maxDiagnostics` | `10` | Error lines appended to an edit |
| `diagnosticsWait` | `3000` | ms an edit waits for errors |
| `requestTimeout` | `30000` | ms a query waits for an answer |
| `startTimeout` | `60000` | ms a server gets to start |
| `startWait` | `15000` | ms a query waits for a starting server before saying "still starting" |
| `autoStart` | `[]` | Languages whose servers start with the session |

A project's `.pig/lsp.json` can name any command to run, like the rest of a project's `.pig` directory: only work in projects you trust.

### Roots

One server per language and project root. The root is the working directory when it is a project of that language (it has `composer.json`, `pubspec.yaml`, …); otherwise the nearest directory above the file with one — so a Flutter app in `app/` of a PHP repository gets a Dart server rooted at `app/`.

## How it works

- JSON-RPC over the server's stdio, framed with `Content-Length`, on pig's event loop: nothing blocks the terminal.
- Every request has a timeout and follows the tool call's cancellation (escape); a cancelled or timed-out request is cancelled on the server too (`$/cancelRequest`).
- Columns are converted between characters and the server's encoding (UTF-16 by default, UTF-8 when the server picks it), so a line with Chinese text or an emoji before the symbol still lands on it.
- Before each query the file is synced from disk, and every open file that changed on disk since (a `sed` in bash, a `git checkout`) is re-sent — the server never answers from a stale copy.
- A server that crashes is restarted by the next query, at most three times in a row, with 1, 2, 4 s pauses; `/lsp` shows why it stopped.
- Servers stop with the session (`shutdown`, `exit`, then SIGTERM and SIGKILL to the process tree).

## Development

The tests run against a pig checkout:

```bash
PIG_DIR=~/path/to/pig ~/path/to/pig/vendor/bin/phpunit -c phpunit.xml
```

`PIG_DIR` defaults to a `pig` directory next to this package. The phpactor tests run when `phpactor` is on PATH or `PHPACTOR_BIN` points at it (or at phpactor.phar), and are skipped otherwise.

## License

MIT
