# OrillaCart extension scripts for VS Code

Highlighting, the static check as you type, completion and hover for `.ocs` files, against the shop they will
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

## Building

```
cd tools/vscode
npm install
npm run build      # dist/extension.js and dist/server.js
npm run package    # orillacart-scripts-<version>.vsix, installable with "Extensions: Install from VSIX…"
```

Press F5 in this folder to run it in an Extension Development Host. The suggestion code is shared with the
admin's editor (`assets/admin/extensions/editor/suggest.ts`); the grammar in `syntaxes/` is for colour only.
