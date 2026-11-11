# 71 · Debugging extensions

Status: built (2026-11-12). PLAN items 616–623.

Writing an extension meant guessing from test results and a run log's one-line message. A debugger needs to stop a
program and look inside, but an extension runs inside a shopper's request: pausing it holds a PHP worker and the
shopper, and on the Platform nothing outside a shop may reach its PHP.

## Decisions

**Record, then step.** The extension's debug build records every line it reaches with the variables in scope, the
functions it enters and what they return, every call to the shop with its answer, and what `debug(value)` marks.
The debugger steps over the recording: forwards, backwards, to breakpoints either way, with the stack and each
frame's variables at every step. The run itself is never paused, so the same debugger works on a test, a console
run and a live checkout.

**Two builds.** The compiler makes the debug build only when a recording is asked for, as a class of its own; the
shop runs the normal one. Both pass the GeneratedCodeGate, which now also takes `finally` and the context's
`enter`, `at`, `result` and `leave`.

**Where, always.** Every function tells the context where it starts and ends, every statement its line, in both
builds: every failure says where it happened and what called it, in the run log, a failed test and a console run.
This costs a normal run a few percent on tight loops (about 2 ms in 30,000 iterations).

**Three sources.** A test debugged, a console run debugged, and live captures: the next runs of the extension's
handlers, up to 20 in up to 60 minutes, by name, armed by a manager and ended by themselves. An unarmed extension
pays nothing: the capture is one autoloaded option.

**Bounded and private.** At most 10,000 steps and 4 MB; long texts, lists and deep maps shaped; secret settings'
values and anything under a key naming a password, token or key hidden; the calls recorded are those that reach
the shop or past it, not the language's helpers. Recordings are kept 24 hours and the latest 20 per extension;
live ones hold customers' data and are a manager's to arm, read and delete.

**One stepping logic, three front ends.** `assets/admin/extensions/editor/replay.ts` steps through a recording; the
Extensions screen's debugger and the VS Code debugger (a Debug Adapter Protocol adapter replaying the recording,
with step back) both use it; the assistant's `debug_package` sums a recording up for a model.

## Left out

Changing a variable and running on from it: the run already happened on the shop. Conditional breakpoints and
logpoints: `debug()` covers the second. A recording of the storefront's templates (Twig): they have their own
errors and the preview.
