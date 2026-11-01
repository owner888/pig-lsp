# Changelog

What changed, newest first, in pig's format: `## [x.y.z] - YYYY-MM-DD`, then only the sections that
have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`). Notes under
`## Unreleased` belong to no version yet; the version itself lives in the git tag.

## [0.3.0] - 2026-11-01

### Changed

- A file whose language has no project marker above it (`composer.json`, `pubspec.yaml`, `go.mod`, …) takes the git repository it is in as its root, so an old project without composer is indexed whole instead of one directory at a time.
- A file in no project at all — no marker and no git repository — gets no server: the tools say so and send the model to grep, since one directory indexed on its own gives answers with blind spots that read like answers. An empty `.phpactor.json` or `git init` at the root brings the server back; a server configured with `"rootMarkers": []` runs anywhere as before.

## [0.2.0] - 2026-11-01

### New Features

- Asking to install a missing language server — The first time a tool needs a server that is not installed, pig asks the user once per language per session whether to run its install command (`composer global require phpactor/phpactor`, `npm install -g …`, `rustup component add rust-analyzer`, `go install …gopls@latest`, `brew install jdtls` on macOS), naming the server, the language, the exact command and that it changes the global environment. Yes runs it in the background (status line, 5-minute limit, escape stops it), finds the server and answers the original query; no is remembered for the session. Calls that arrive while the question is open share one question. Without a UI nothing is asked or installed.

### Added

- Added `/lsp-install [language]`: install a missing server on request (it still asks), also after a no or a failed install.
- Added `installers` to the built-in server specs: structured commands (an argument list, never a shell line), offered only when their installer — composer, npm, rustup, go, brew — is itself installed. intelephense is offered only when there is no composer to install phpactor with; Dart has none and keeps its prose hint.
- Added the installers' bin directories to where servers are looked for: the one the installer names (`composer global config bin-dir --absolute`, `go env GOBIN` / `GOPATH`, `npm prefix -g`, `brew --prefix`), then `~/.composer/vendor/bin`, `~/.config/composer/vendor/bin`, `~/go/bin`, `~/.cargo/bin`. A server found off `PATH` is started with its directory first on its `PATH` (phpactor starts itself again by name to check a file, and found nowhere it reported every file clean), and the user is told to add the directory to `PATH`.

### Changed

- A tool whose server is missing no longer hands the model the install command: it says whether the user was asked, declined or the install failed (with the installer's last lines), tells the model not to install it itself with bash, and to fall back to grep and say the results are textual. The `lsp-code-navigation` skill says the same.
- `/lsp` shows a missing server as installable (with the command), declined this session, or failed (with why).

## [0.1.0] - 2026-11-01

### New Features

- Language-server tools for pig — `lsp_definition`, `lsp_references`, `lsp_implementation`, `lsp_hover`, `lsp_symbols`, `lsp_diagnostics`, `lsp_rename` (a preview) and `lsp_code_actions` (nothing applied), over a zero-dependency JSON-RPC client on pig's event loop. A position is `path` + `line` + `query`; every location comes back as `path:line:col` with its line, project files before `vendor/` and `node_modules/`.
- Errors after every edit — while a language's server is running, each `write` and `edit` of one of its files gets the server's errors for it appended, waiting for the server's answer (up to 3 s) rather than a fixed delay.

### Added

- Added built-in servers: phpactor (or intelephense) for PHP, Dart, typescript-language-server, pyright, rust-analyzer, gopls and jdtls; one server per language and project root, the root found from `composer.json`, `pubspec.yaml` and the like.
- Added configuration from pig's `lsp` setting, `.pi-lsp.json` and `.pig/lsp.json`: commands, arguments, environment, `initializationOptions`, `settings`, extra languages, timeouts, `autoStart` and which languages get errors after edits.
- Added `/lsp` (running servers, roots, what they are busy with, why one failed) and `/lsp-restart [language]`.
- Added the `lsp-code-navigation` skill: which tool to use when, and how to trace a call through an interface to the implementation that runs.
- Added request timeouts and cancellation (escape cancels on the server too), UTF-16/UTF-8 column conversion, re-sending files changed on disk before each query, restarting a crashed server at most three times in a row, and stopping every server with the session.
