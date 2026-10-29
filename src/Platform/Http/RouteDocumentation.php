<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Http;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\WordPress\Security\TransientRateLimiter;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Routing\RouteInputs;
use OrillaCart\Shared\Http\Routing\RuleWords;
use OrillaCart\Shared\Security\Capability;

/**
 * The shop's REST routes as documentation, read from the routes themselves: each route's methods, who may call
 * it, its rate limit and nonce, what its action says it does, and the fields its body or query takes, with their
 * rules, from the DTO the action maps the request to. docs/rest-api.md is what markdown() writes (bin/rest-docs),
 * and a test fails when it falls behind the code.
 */
final readonly class RouteDocumentation
{
    private const string INTRO = <<<'MD'
        # The shop's REST API

        Written by `bin/rest-docs` from the routes themselves; do not edit by hand.

        Every route is under `/wp-json/orillacart/v1` (or `?rest_route=/orillacart/v1/…` without pretty permalinks).
        Bodies are JSON; field names are in snake_case. Answers are JSON, as the shop's screens read them.

        ## Who may call a route

        - **Anyone**: the storefront's routes (the cart, checkout, receipts, reviews). Changes need a nonce:
          `GET /session` gives a visitor one, never cached; send it as `X-WP-Nonce`.
        - **Logged-in customers** and **shop managers**: with WordPress's login cookie and a nonce
          (`X-WP-Nonce`, `wp_create_nonce('wp_rest')`), as the shop's screens do.
        - **Integrations**: with an API key from Shop → Integrations, `Authorization: Bearer oc_…` (or
          `X-OrillaCart-Key` where the host drops `Authorization`), acting as the shop manager who made it. A
          read-only key changes nothing. Keys need no nonce. WordPress's application passwords (Basic auth) work
          too.

        ## Languages

        On a site in several languages (Polylang, WPML) a call is answered in the language of
        `X-OrillaCart-Locale` (a WordPress locale such as `de_DE`) when the site offers it, else in the default one:
        the names of products, attributes, shipping and payment methods, and an order's language for its emails.
        The storefront sends its page's. Admin routes take and give every language in `translations`.

        ## Errors

        `{"code": "orillacart_…", "message": "…", "data": {"status": 422}}`, with `data.violations`
        (`[{"field", "message"}]`) for fields that are not valid. 401: log in, or a key the shop has not;
        403: not allowed, or the nonce is missing or expired; 404: no such thing; 422: refused, as the message says;
        429: too many tries, see `Retry-After`.

        MD;

    public function __construct(private RouteCollection $routes) {}

    /** The routes of the plugin in the folder, without WordPress: for bin/rest-docs and the test. */
    public static function ofPlugin(string $pluginDir): self
    {
        $builder = Kernel::buildContainer($pluginDir);
        $builder->getDefinition(RouteCollection::class)->setPublic(true);
        $builder->compile();
        $routes = $builder->get(RouteCollection::class);
        \assert($routes instanceof RouteCollection);

        return new self($routes);
    }

    /**
     * @return list<array{method: string, path: string, access: string, nonce: bool, limit: ?string, summary: string, body: list<array{name: string, type: string, rules: string}>, query: list<array{name: string, type: string, rules: string}>}>
     */
    public function routes(): array
    {
        $documented = [];
        foreach ($this->routes->all() as $route) {
            [$body, $query] = RouteInputs::dtos($route->action);
            foreach ($route->methods as $method) {
                $documented[] = [
                    'method' => $method,
                    'path' => $route->path,
                    'access' => self::access($route),
                    'nonce' => $route->needsNonce($method),
                    'limit' => self::limit($route->guard),
                    'summary' => RouteInputs::summary($route->action),
                    'body' => $route->isSafe($method) || $body === null ? [] : self::fields($body),
                    'query' => $route->isSafe($method) && $query !== null ? self::fields($query) : [],
                ];
            }
        }
        usort($documented, static fn(array $a, array $b): int => [$a['path'], self::order($a['method'])] <=> [$b['path'], self::order($b['method'])]);

        return $documented;
    }

    public function markdown(): string
    {
        $out = [self::INTRO];
        $area = null;
        foreach ($this->routes() as $route) {
            $first = explode('/', trim($route['path'], '/'))[0] ?? '';
            if ($first !== $area) {
                $area = $first;
                $out[] = "## /{$area}\n";
            }
            $out[] = "### `{$route['method']} {$route['path']}`\n";
            if ($route['summary'] !== '') {
                $out[] = $route['summary'] . "\n";
            }
            $out[] = '- Who: ' . $route['access'] . ($route['nonce'] ? '; needs a nonce (not with an API key)' : '') . ($route['limit'] !== null ? '; at most ' . $route['limit'] : '');
            foreach (['body' => 'Body (JSON)', 'query' => 'Query'] as $kind => $label) {
                if ($route[$kind] !== []) {
                    $out[] = "- {$label}:";
                    foreach ($route[$kind] as $field) {
                        $out[] = "  - `{$field['name']}` ({$field['type']})" . ($field['rules'] === '' ? '' : ': ' . $field['rules']);
                    }
                }
            }
            $out[] = '';
        }
        $shapes = $this->shapes();
        if ($shapes !== []) {
            $out[] = "## Shapes\n";
            $out[] = "The objects fields above take.\n";
            foreach ($shapes as $name => $fields) {
                $out[] = "### {$name}\n";
                foreach ($fields as $field) {
                    $out[] = "- `{$field['name']}` ({$field['type']})" . ($field['rules'] === '' ? '' : ': ' . $field['rules']);
                }
                $out[] = '';
            }
        }

        return rtrim(implode("\n", $out)) . "\n";
    }

    /**
     * The shop's objects the routes' fields take, and theirs, by short name.
     *
     * @return array<string, list<array{name: string, type: string, rules: string}>>
     */
    private function shapes(): array
    {
        $waiting = [];
        foreach ($this->routes->all() as $route) {
            foreach (RouteInputs::dtos($route->action) as $dto) {
                if ($dto !== null) {
                    array_push($waiting, ...RouteInputs::nested($dto));
                }
            }
        }
        $shapes = [];
        while ($waiting !== []) {
            $class = array_shift($waiting);
            $name = self::shortType($class);
            if (isset($shapes[$name])) {
                continue;
            }
            $shapes[$name] = self::fields($class);
            array_push($waiting, ...RouteInputs::nested($class));
        }
        ksort($shapes);

        return $shapes;
    }

    private static function order(string $method): int
    {
        return (int) array_search($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private static function access(RouteDefinition $route): string
    {
        return match (true) {
            $route->signed => 'the payment provider, signed',
            $route->capability === Route::PUBLIC => 'anyone',
            $route->capability === Capability::MANAGE_SHOP => 'shop managers',
            $route->capability === Capability::ACCOUNT => 'logged-in customers',
            default => "users who can {$route->capability}",
        };
    }

    private static function limit(?string $guard): ?string
    {
        $limit = $guard === null ? null : TransientRateLimiter::limits()[$guard] ?? null;
        if ($limit === null) {
            return null;
        }
        [$count, $seconds] = $limit;

        return \sprintf(match ($seconds) {
            3600 => '%d an hour',
            600 => '%d every ten minutes',
            86400 => '%d a day',
            default => "%d every {$seconds} seconds",
        }, $count) . ' from each address';
    }

    /**
     * @param class-string $dto
     *
     * @return list<array{name: string, type: string, rules: string}>
     */
    private static function fields(string $dto): array
    {
        $fields = [];
        foreach (RouteInputs::fields($dto) as $field) {
            $rules = array_values(array_filter(array_map(RuleWords::of(...), $field->rules), static fn(string $rule): bool => $rule !== ''));
            if (!$field->required) {
                $default = $field->default;
                if ($default !== null && $default !== [] && $default !== '' && \is_scalar($default)) {
                    $rules[] = 'default ' . var_export($default, true);
                }
            } else {
                array_unshift($rules, 'required');
            }
            $fields[] = [
                'name' => $field->name,
                'type' => self::shortType($field->type),
                'rules' => implode('; ', $rules),
            ];
        }

        return $fields;
    }

    /** "?OrillaCart\Ordering\Application\Dto\AddressData" reads "AddressData|null". */
    private static function shortType(string $type): string
    {
        $nullable = str_starts_with($type, '?');
        $short = (string) preg_replace('/(?:\w+\\\\)+(\w+)/', '$1', ltrim($type, '?'));

        return $nullable ? $short . '|null' : $short;
    }
}
