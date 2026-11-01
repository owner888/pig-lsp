# pig-lsp

给 pig 编码助手用的语言服务器（LSP）工具。grep 找的是文本，这些工具找的是**符号**：定义不会和调用混在一起，`Order::pay()` 不会和 `Invoice::pay()` 混在一起，注释里提到的名字也不算引用。

[English](README.md)

## 安装

```bash
pig install git:github.com/owner888/pig-lsp
```

然后为你用到的语言装好对应的语言服务器（见下文），或者等 pig-lsp 第一次用到时让它问你要不要装。本包没有任何 Composer 依赖。

## 工具

| 工具 | 回答什么问题 |
| --- | --- |
| `lsp_definition` | 这个调用最终去了哪里？ |
| `lsp_references` | 谁在用这个方法 / 类 / 函数？ |
| `lsp_implementation` | 哪些类实现了这个接口？哪些方法实现了这个接口方法？ |
| `lsp_hover` | 某个位置的类型和文档 |
| `lsp_symbols` | 文件大纲（`path`），或在整个工作区按名字搜索（`query`） |
| `lsp_diagnostics` | 某个文件的错误和警告，或所有已检查文件的（`"*"`） |
| `lsp_rename` | 全项目重命名的预览——不会写入任何文件 |
| `lsp_code_actions` | 服务器提供的快速修复和重构，附带各自的改动——不会应用 |

位置用 **`path` + `line` + `query`**（该行上写着的名字）给出，列号由工具自己找。只给 `query` 时，取文件里这个名字的声明。也可以用 `line` + `character`。行号和列号从 1 开始，列按字符数算（不是字节）。

每个位置都以 `路径:行:列` 加上该行的代码返回，按文件分组，项目自己的文件排在 `vendor/`、`node_modules/` 之前：

```
2 implementations of `charge` in 2 files:
src/Pay/AlipayPay.php:7:5  public function charge(int $amount): string

src/Pay/WechatPay.php:7:5  public function charge(int $amount): string
```

### 每次编辑后自动附上错误

某种语言的服务器在运行时，`write` / `edit` 该语言的文件后，服务器报告的该文件**错误**会追加到工具结果后面（最多 10 行）：

```
⚠ LSP (php): 1 error in src/PayFactory.php after this change:
src/PayFactory.php:15:29 error: Class "AliPayy" not found [phpactor]
```

编辑会等服务器的回复（最多 3 秒），而不是固定睡一段时间。编辑不会启动服务器——只有查询才会——所以编辑永远不用等服务器启动。

### 命令

- `/lsp`——正在运行的服务器、它们的根目录、正在忙什么，以及某个服务器为什么失败（附 stderr 末尾）。
- `/lsp-restart [语言]`——停止全部（或某一个）服务器并重新读取配置，下一次查询会重新启动。
- `/lsp-install [语言]`——安装缺失的服务器（先询问，见下文）。不带语言时：本项目需要的那些。

### Skill

`lsp-code-navigation` 告诉模型什么时候用哪个工具，以及怎样顺着接口调用找到真正执行的代码：`lsp_implementation` → 对接口用 `lsp_references`（找到容器绑定或工厂）→ 读那段代码 → 说明运行的是哪个实现，或者说明取决于运行时输入。

比如一个 `Pay` 接口有微信支付和支付宝两个实现，`$this->pay->charge()` 处：`lsp_implementation` 列出 `WechatPay::charge` 和 `AlipayPay::charge`；`lsp_references` 找到 `PayFactory::make()` 里的 `match ($channel)`；于是模型能说清“由 `$channel` 决定，`'wechat'` 走微信”，而不是随便挑一个。

## 语言服务器

使用找到的第一个：先找 `PATH`，再找安装器常用但往往不在 `PATH` 上的目录（composer 全局 `vendor/bin`、`~/go/bin`、`~/.cargo/bin`）。

| 语言 | 服务器 | 安装 |
| --- | --- | --- |
| PHP | `phpactor language-server` | `composer global require phpactor/phpactor`，或下载 phpactor.phar 放到 PATH 上并命名为 `phpactor` |
| PHP（备选） | `intelephense --stdio` | `npm install -g intelephense` |
| Dart / Flutter | `dart language-server --protocol=lsp` | Dart 或 Flutter SDK |
| TypeScript / JavaScript | `typescript-language-server --stdio` | `npm install -g typescript-language-server typescript` |
| Python | `pyright-langserver --stdio`（或 basedpyright、pylsp） | `npm install -g pyright` |
| Rust | `rust-analyzer` | `rustup component add rust-analyzer` |
| Go | `gopls` | `go install golang.org/x/tools/gopls@latest` |
| Java | `jdtls` | `brew install jdtls` |

### 服务器没装时

工具第一次需要一个没装的服务器时，pig 会问你——每种语言每个会话只问一次：

```
Install phpactor?
No php language server is installed, so pig falls back to grep for …
pig-lsp can install phpactor by running:
    composer global require phpactor/phpactor
This changes your global composer environment. …
```

- **是**：命令在后台运行（状态栏显示 `LSP: installing phpactor…`，最长 5 分钟，Esc 可中止），然后找到服务器——在 `PATH` 上，或在安装器说的安装目录里（`composer global config bin-dir`、`go env GOPATH`、`npm prefix -g`）——原来的查询接着完成。装在 `PATH` 之外也能用（启动时把它的目录加到服务器进程的 `PATH` 前面，phpactor 的诊断需要这个），结果里会建议把该目录加进你的 `PATH`。
- **否**：本会话不再询问；工具告诉模型服务器没装、改用 grep。
- **安装失败**：显示安装器输出的最后几行，本会话不再尝试。
- 询问期间同时到达的多个调用等待同一个回答：只问一次，而不是每个调用问一次。
- 只提供上表里的命令，并且只在对应安装器（composer、npm、rustup、go、brew）本身已安装时提供；没有 composer 装 phpactor 时才提供 intelephense，jdtls 只在 macOS 上提供，Dart 从不提供（请安装 SDK）。你自己配置的服务器（`command`）由你自己安装。其余情况只显示安装提示。
- 没有 UI 时（`pig -p`、没有 UI 的 RPC）不询问、不安装。

无论哪种情况，模型都会被告知不要自己安装服务器，而是退回 grep，并说明结果是文本匹配。`/lsp` 会把缺失的服务器标为可安装、已拒绝或安装失败；`/lsp-install` 会再问一次。

PHP 推荐 phpactor：免费，能找到接口和接口方法的实现。intelephense 免费版不提供“转到实现”（这时 `lsp_implementation` 会说明并建议改用 `lsp_references`）。phpactor 启动后会在后台为项目建索引；大项目上，最初一分钟内的实现和引用查询可能不完整（服务器报告仍在索引时，工具会注明）。

## 配置

可选。按以下顺序读取，后者覆盖前者：

1. pig 的设置，键 `lsp`（`~/.pig/agent/settings.json` 或项目设置）
2. 项目里的 `.pi-lsp.json`（pi-lsp-extension 的配置文件，原样兼容）
3. 项目里的 `.pig/lsp.json`

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

| 键 | 默认值 | 含义 |
| --- | --- | --- |
| `servers.<语言>.command`、`args`、`env` | 内置 | 如何启动服务器 |
| `servers.<语言>.initializationOptions` | | 随 `initialize` 发送 |
| `servers.<语言>.settings` | | 回答 `workspace/configuration`，启动后也会发送一次 |
| `servers.<语言>.extensions` | | 它负责的文件扩展名（非内置服务器用） |
| `servers.<语言>.rootMarkers` | 内置 | 标识项目根目录的文件（`composer.json`、`pubspec.yaml`……） |
| `servers.<语言>.installers` | 内置 | `/lsp-install` 可以运行的命令：`[{"binary": "zls", "with": "brew", "command": ["brew", "install", "zls"]}]`——只在 `with` 已安装时提供，且只用于通过 `candidates`（`[["zls"]]`）查找的服务器，不用于你自己的 `command` |
| `servers.<语言>.disabled` | `false` | 不启动它 |
| `servers.<语言>.diagnosticsSettle` | `400` | 服务器安静多少毫秒后，编辑的错误才算最终结果 |
| `autoInjectDiagnostics`（或 `autoDiagnostics`） | `true` | `true`、`false` 或语言列表 |
| `maxDiagnostics` | `10` | 追加到编辑后的错误行数上限 |
| `diagnosticsWait` | `3000` | 编辑等待错误的毫秒数 |
| `requestTimeout` | `30000` | 查询等待回复的毫秒数 |
| `startTimeout` | `60000` | 服务器启动的时限（毫秒） |
| `startWait` | `15000` | 查询等待正在启动的服务器多久，之后回答“仍在启动” |
| `autoStart` | `[]` | 随会话一起启动的语言服务器 |

和项目 `.pig` 目录里的其他内容一样，项目的 `.pig/lsp.json` 可以指定任意要运行的命令：只在你信任的项目里工作。

### 根目录

每种语言、每个项目根目录一个服务器。如果工作目录就是该语言的项目（有 `composer.json`、`pubspec.yaml` 等），根目录就是工作目录；否则取文件上方最近的含标识文件的目录——所以 PHP 仓库里 `app/` 下的 Flutter 应用，会得到一个以 `app/` 为根的 Dart 服务器。都没有的话，用文件所在的 git 仓库根目录（`.git` 目录，或 worktree 里的 `.git` 文件）。

两者都没有——文件上方既没有该语言的标识文件，也不在 git 仓库里——就不启动服务器：单独一个目录建出的索引有盲区，查不到的结果看上去却像答案，所以工具会说明情况，模型改用 grep。想用语言服务器，就在项目根目录放上标识文件（PHP 放一个空的 `.phpactor.json` 就行）或者 `git init`。给服务器配置 `"rootMarkers": []` 则不受此限，在哪都能跑。

## 工作原理

- 在 pig 的事件循环上，通过服务器的 stdio 收发 JSON-RPC（`Content-Length` 分帧），不阻塞终端。
- 每个请求都有超时，并跟随工具调用的取消（按 Esc）；取消或超时的请求也会通知服务器取消（`$/cancelRequest`）。
- 列号在“字符数”和服务器的编码之间换算（默认 UTF-16，服务器选择时用 UTF-8），所以符号前面有中文或 emoji 时也能准确定位。
- 每次查询前都从磁盘同步文件，并且所有已打开、之后在磁盘上被改过的文件（bash 里的 `sed`、`git checkout`）都会重新发送——服务器不会基于过期内容回答。
- 崩溃的服务器会在下一次查询时重启，连续最多三次，间隔 1、2、4 秒；`/lsp` 会显示它为什么停了。
- 会话结束时停止服务器（`shutdown`、`exit`，然后对进程树发 SIGTERM、SIGKILL）。

## 开发

测试依赖一份 pig 源码：

```bash
PIG_DIR=~/path/to/pig ~/path/to/pig/vendor/bin/phpunit -c phpunit.xml
```

`PIG_DIR` 默认是本包旁边的 `pig` 目录。`phpactor` 在 PATH 上，或 `PHPACTOR_BIN` 指向它（或 phpactor.phar）时，会运行 phpactor 集成测试，否则跳过。

## 许可证

MIT
