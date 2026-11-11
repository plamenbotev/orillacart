# OrillaCart extension scripts for VS Code

Highlighting, the static check as you type, completion, hover and a debugger for `.ocs` files, against the shop they will
run on. The shop's compiler is the only implementation of the language: this extension runs a small language
server that sends the open script and its folder's other scripts to the site's `POST /script/check` and reads
the site's `GET /script-reference` for completions, so what VS Code says is exactly what the shop will say.

## Setup

1. In the shop, Shop › Integrations, make an API key for a manager (it may be read-only: the check and the
   reference change nothing; push needs a key that may write).
2. In VS Code settings, set `orillacart.siteUrl` (such as `https://shop.example`) and `orillacart.apiKey`.
3. Open a folder. An extension is a folder named after its id with its `.ocs` files and `templates/`.

## Commands

- **OrillaCart: Pull an extension's files from the site**: asks the id, writes the files into `<id>/`.
- **OrillaCart: Push this folder to the site as an extension**: sends the open script's folder; installs the
  extension as a draft when the site has none of that id, else writes the files that changed. Enabling and
  granting permissions stay in the admin, since that is the manager's act.
- **OrillaCart: Check the open scripts now**.
- **OrillaCart: Run this extension's tests on the site**: sends the folder's files and runs their `test`
  declarations on the site, saved nowhere; the outcomes go to the "OrillaCart tests" output channel.

## Debugging

An extension's run is debugged by *recording* it on the shop and stepping through the recording afterwards, in
VS Code, forwards and backwards. The run already happened when the debugger opens, so nothing pauses the shop:
a breakpoint, a step or a look at the variables moves over what was recorded: every line reached, the variables
at each, the calls to the shop with their answers, `debug()` values and the error the run ended with.

Press F5 with an `.ocs` file open and no launch configuration and the debugger asks what to debug: one of the
folder's tests (its `test "…"` declarations), one of its top-level functions (then its arguments as a JSON
list), or one of the recordings the shop keeps. Or say it in `.vscode/launch.json`:

```json
{
	"version": "0.2.0",
	"configurations": [
		{
			"type": "orillacart",
			"request": "launch",
			"name": "Test \"refuses big ones\"",
			"folder": "${workspaceFolder}/quote",
			"test": "refuses big ones"
		},
		{
			"type": "orillacart",
			"request": "launch",
			"name": "fn total",
			"folder": "${workspaceFolder}/quote",
			"function": "total",
			"arguments": [ [ { "unit": 2, "qty": 3 } ] ]
		},
		{
			"type": "orillacart",
			"request": "launch",
			"name": "Recording #12",
			"folder": "${workspaceFolder}/quote",
			"recording": 12,
			"stopOnEntry": false
		}
	]
}
```

- `test`: runs that test on the shop over this folder's files (saved nowhere) and records it. With a read-only
  API key the shop refuses files sent, so the test runs over the extension's saved files, and the debug console
  says so.
- `function` and `arguments`: runs one function over the extension's saved files (push first to run yours).
- `recording`: a recording the shop keeps, from a debugged test, the admin's console or a live capture.
- `folder`: the extension's folder, named after its id; by default the one of the open `.ocs` script.
- `stopOnEntry`: `true` by default; with `false` the debugger goes straight to the first breakpoint.

The commands **OrillaCart: Debug a test…** and **OrillaCart: Debug a recording…** do the same without a launch
configuration, and a **Debug test** lens sits above each `test "…"` declaration.

Stepping: Continue goes to the next breakpoint, or to the end; Step Over, Step Into and Step Out as usual; **Step
Back** goes back a line, calls run through backwards (the reverse of Step Over), and **Reverse Continue** back to
the breakpoint before, or the start. A run that failed stops at its error as an exception, with its message;
continuing from the run's last stop ends the session. Hover a name, or watch `item.unit` or `items[0]`, to see
its value at that point. The debug console shows what ran and how it ended, the shop's calls and `debug()`
values as the replay passes them, and a warning when the run made more steps than a recording keeps (its end is
then missing).

Lines always match what ran: a file whose local content is the recorded one opens from the folder; one changed
since the run, or not in the folder, opens as it ran, read-only.

Live runs (a shopper's checkout, a webhook) are recorded by the Extensions screen's capture: arm it there for the
next runs of an extension, then debug the recordings it kept with **OrillaCart: Debug a recording…**.

## Building

```
cd tools/vscode
npm install
npm run build      # dist/extension.js and dist/server.js
npm test           # builds, then drives the debug adapter over a recorded run
npm run package    # orillacart-scripts-<version>.vsix, installable with "Extensions: Install from VSIX…"
```

Press F5 in this folder to run it in an Extension Development Host. The suggestion code is shared with the
admin's editor (`assets/admin/extensions/editor/suggest.ts`); the grammar in `syntaxes/` is for colour only.
