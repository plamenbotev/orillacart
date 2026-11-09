# 58 · Extensions as zip files

Status: built (2026-11-09). PLAN items 347–350.

Design 56 meant an extension to move as "a zip with a checksum"; it was built as a JSON file of its files. A zip
is what people expect to download, keep in a repository, unpack, edit in an editor and upload again, so an
extension is installable from one, and exported as one.

## Decisions

**The zip holds the extension's files as they are in the package**: `main.ocs`, `templates/note.html.twig`,
`README.md`, in folders as the package names them. They may sit at the zip's root or in one folder around them
all (`payfast-1.2.0/main.ocs`), as a download of a repository has them; that folder is left out.

**`extension.json` at the top is optional.** An export writes it: the id, name, version and format of the JSON
export, and the translations (design 57), without the files, which are the zip's own. Without it, the id and the
name come from the scripts' `extension "id" { name }` block, so a zip of hand-written files installs as it is.
A zip with neither is refused, saying so.

**What is not an extension's file is left out, and named.** A zip from a computer brings `__MACOSX/`,
`.DS_Store`, a `LICENSE`, a `package.json`: the files a package cannot hold (design 56: `.ocs`, `.html.twig`,
`.md`) are skipped, hidden files and folders without a word, the others listed in the answer, so the manager sees
what did not come in. A folder is never written to disk: every entry is read into memory and goes through the
package's own checks of path, length and number, so a path with `..`, an absolute one, or a link is refused by
the same rule as a file written in the editor.

**Limits before reading.** At most 10 MB uploaded, 500 entries, and what the package takes (50 files of 512 KB);
each entry is read up to its limit and no further, whatever its header claims, so a zip bomb stops at the first
file too big. An encrypted entry, a file that is not UTF-8 text and a zip that does not open are refused.

**Installing a zip is importing it**: a new extension is a draft; one with the same id gets its files replaced,
keeps running the old ones until enabled again, and keeps a version (design 56) of the change. Settings and
permissions stay with the shop, as with the JSON file.

## How it fits

- `POST /extensions/upload`, a multipart upload in the `file` field, answers the extension and the files
  skipped. An assistant tool, as every manager route, sending the zip as base64 or an address.
- `GET /extensions/{id}/archive` gives the zip as base64 with its file name (`payfast-1.2.0.zip`), since the REST
  API speaks JSON; the Extensions screen downloads it from Export. The JSON export stays for tools.
- The Extensions screen's Import takes a `.zip` or the `.json` of before.
- `wp orillacart extension install <file.zip>` and `wp orillacart extension export <id> [<file.zip>]`.
- `Packages\Application\PackageArchives` reads and writes; `ZipPackageArchives` does it with PHP's `zip`
  extension. The plugin does not require it: on a server without it, a zip is refused with that reason and the
  JSON file still works.
