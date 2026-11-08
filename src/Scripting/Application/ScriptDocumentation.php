<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

/**
 * docs/extension-scripts.md: the guide to the script language with the reference generated from the host
 * catalogue, so what the docs say a function takes is what it takes. `bin/script-docs` writes it; a test keeps it
 * current.
 */
final readonly class ScriptDocumentation
{
    public function __construct(private ScriptReference $reference) {}

    public function markdown(): string
    {
        $reference = $this->reference->reference();
        $out = <<<'MD'
            # Extension scripts

            An extension adds to a shop without a PHP plugin: a payment or shipping provider, endpoints and pages,
            records and fields, templates, handlers that run after the shop's events. It is written in the shop's own
            script language, lives in the site's database, and runs in a sandbox with a budget and the permissions a
            manager granted it (design 56). Write one on Settings › Extensions, or let an agent write it through the
            assistant's `extension_*` tools; the same reference is the MCP resource `orillacart://extensions/reference`.
            This is the catalogue: every declaration, function, filter and test in a line. The guide to writing one,
            with what each handler receives and answers and how a script fails, is `docs/extension-scripts-guide.md`.

            ## How a script reads

            MD;
        $out .= $reference['language'] . "\n\n";
        $out .= <<<'MD'
            A package is one or more `.ocs` files, compiled together, plus templates under `templates/`. Declarations
            at the top level say what the extension adds; the functions inside them are what the shop calls. A top-level
            `fn` is the extension's own, callable from its other functions and the console.

            ```
            extension "payfast" {
              name: "PayFast"
              version: "1.0.0"
              needs: { orders: read, http: ["api.payfast.example"] }
            }

            settings {
              secret: secret { label: "API secret", required: true }
            }

            payment_gateway "payfast" {
              name: "Pay with PayFast"
              fn start(session, settings) {
                let answer = http.post("https://api.payfast.example/sessions", {
                  json: { amount: session.amount, currency: session.currency, return_url: session.return_url },
                  headers: { Authorization: "Bearer " ~ settings.secret },
                })
                if answer.status != 200 { fail unavailable("PayFast answered " ~ answer.status) }
                return payment.redirect(answer.json.url, answer.json.id)
              }
            }
            ```

            ## Permissions

            An extension declares in `needs` what it must reach: `orders: read`, `orders: write`, `products: read`,
            `products: write`, `customers: read`, `http: ["host", …]` (the only hosts `http.*` may call, over https),
            `templates: override` (it may replace the shop's own templates). A manager grants them when enabling it;
            a function that needs one the extension was not granted is refused by the static check before, and by the
            runtime after. Nothing else is reachable: no PHP, no files, no queries, no WordPress.

            ## Budgets and failures

            A run has 100 000 steps, 2 seconds, 16 MB of values, 200 calls, 5 HTTP calls and 20 queries. A run that
            exceeds them is stopped; the script cannot catch that. `fail unavailable("…")` says a provider cannot be
            reached (the shop tries later), `fail refused("…")` is an answer to show whoever asked, any other failure is
            a problem the shop logs. Ten failures in a row stop the extension; a manager enables it again after reading
            the problem on the Extensions screen.

            ## Declarations

            MD;
        foreach ($reference['declarations'] as $declaration => $what) {
            $out .= '- `' . $declaration . '`: ' . $what . "\n";
        }
        $out .= "\n## Functions\n\nEvery call out of a script is one of these; `[needs …]` is the permission it takes.\n\n";
        $namespace = null;
        // The builtins first, then each namespace: the catalogue sorts by name, which mixes them.
        $functions = $reference['functions'];
        usort($functions, static fn(array $a, array $b): int => [str_contains($a['name'], '.') ? 1 : 0, $a['name']] <=> [str_contains($b['name'], '.') ? 1 : 0, $b['name']]);
        foreach ($functions as $function) {
            $parts = explode('.', $function['name']);
            $group = \count($parts) > 1 ? $parts[0] : 'builtin';
            if ($group !== $namespace) {
                $namespace = $group;
                $out .= "\n### " . ($group === 'builtin' ? 'Without a namespace' : $group) . "\n\n";
            }
            $out .= '- ' . $function['summary'] . ($function['needs'] === null ? '' : ' [needs ' . $function['needs'] . ']') . "\n";
        }
        $out .= "\n## Filters\n\n`" . implode('`, `', $reference['filters']) . "`\n\n## Tests\n\n`" . implode('`, `', $reference['tests']) . "`\n";

        return $out;
    }
}
