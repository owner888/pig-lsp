---
name: lsp-code-navigation
description: Navigate code by meaning with the lsp_* tools instead of grep — where a call goes, who calls a method, which classes implement an interface and which one actually runs, safe renames. Use before changing a method that has same-named siblings, when tracing an interface to its implementation, or when grep would mix definitions, calls and comments.
---

# Code navigation with the language server

grep finds text. The `lsp_*` tools find **symbols**: a definition is not confused with a call, `Order::pay()` is not confused with `Invoice::pay()`, and a comment or string that mentions the name is not a reference. Reach for them whenever the question is "which one", not "where does this text appear".

## Pointing at a symbol

Give `path` + `line` + `query` (the name as written on that line). The column is found for you; that is far more reliable than counting characters. `query` alone finds the declaration of that name in the file. Lines are 1-based.

```
lsp_definition   path=src/Order/Checkout.php line=42 query=charge
```

## Which tool

| Question | Tool |
| --- | --- |
| Where does this call go? | `lsp_definition` at the call site |
| Who calls this method? What breaks if I change its signature? | `lsp_references` (`includeDeclaration` off by default) |
| What implements this interface / overrides this method? | `lsp_implementation` |
| What type is this variable? What does this method take? | `lsp_hover` |
| What is in this file? Where is class `X` in the project? | `lsp_symbols` (`path`, or `query` for the workspace) |
| Does this file have errors? | `lsp_diagnostics` (edits get their new errors appended automatically) |
| Rename everywhere | `lsp_rename` (a preview; apply with the edit tool) |
| What quick fixes are there? | `lsp_code_actions` |

## Tracing an interface to the code that runs

A call through an interface (`$this->pay->charge($amount)` where `pay` is a `PayInterface`) has no single answer in the source: the definition is the interface, and the code that runs is whichever object was put there. Work it out instead of guessing:

1. **`lsp_implementation`** on the interface method (or the call) — every concrete `charge()`: say `WechatPay::charge` and `AlipayPay::charge`.
2. **`lsp_references`** on the interface name — this finds where it is bound or constructed: a container binding (`bind(PayInterface::class, …)`), a factory (`match ($channel) { 'wechat' => new WechatPay(...), … }`), a constructor or config file.
3. **Read** that binding or factory branch.
4. **Say which** implementation runs, and why — or, when it depends on runtime input (a config value, a request parameter, the environment), say exactly that and name the input that decides it. Do not pick one because it is listed first.

When changing such a method, change it in the interface and in every implementation `lsp_implementation` listed, then check each with `lsp_diagnostics`.

## When the server is not there

A tool says so — not installed, still starting (try again shortly), or failed (`/lsp` shows why). Then fall back to grep, and say the results are textual.

A server that is not installed is the user's to install: pig asks them when a tool first needs it, and the tool's answer says whether they were asked, declined, or the install failed. Do not install a language server yourself with bash (`composer global require`, `npm install -g`, `go install`, …) — it changes the user's global environment. If the user wants one, `/lsp-install` installs it. `/lsp` lists the servers; `/lsp-restart` restarts them after a configuration change.
