<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Http;

use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\WordPress\Security\TransientRateLimiter;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Security\Capability;

/**
 * The shop's REST routes as documentation, read from the routes themselves: each route's methods, who may call
 * it, its rate limit and nonce, what its action says it does, and the fields its body or query takes, with their
 * rules, from the DTO the action maps the request to. docs/rest-api.md is what markdown() writes (bin/rest-docs),
 * and a test fails when it falls behind the code.
 */
final readonly class RouteDocumentation
{
    private const string SNAKE = '/(?<!^)[A-Z]/';
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
            [$body, $query] = self::inputs($route->action);
            foreach ($route->methods as $method) {
                $documented[] = [
                    'method' => $method,
                    'path' => $route->path,
                    'access' => self::access($route),
                    'nonce' => $route->needsNonce($method),
                    'limit' => self::limit($route->guard),
                    'summary' => self::summary($route->action),
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
            foreach (self::inputs($route->action) as $dto) {
                if ($dto !== null) {
                    array_push($waiting, ...self::nested($dto));
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
            array_push($waiting, ...self::nested($class));
        }
        ksort($shapes);

        return $shapes;
    }

    /**
     * The shop's classes the DTO's fields are of, or lists of.
     *
     * @param class-string $dto
     *
     * @return list<class-string>
     */
    private static function nested(string $dto): array
    {
        $constructor = new \ReflectionClass($dto)->getConstructor();
        if ($constructor === null) {
            return [];
        }
        $code = (string) file_get_contents((string) new \ReflectionClass($dto)->getFileName());
        $nested = [];
        $documented = self::paramTypes((string) $constructor->getDocComment());
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $names = [];
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $names[] = $type->getName();
            }
            if (preg_match('/list<\??([A-Z]\w+)>/', $documented[$parameter->getName()] ?? '', $match) === 1) {
                $names[] = preg_match('/^use ([\w\\\\]+\\\\' . $match[1] . ');$/m', $code, $use) === 1 ? $use[1] : (new \ReflectionClass($dto)->getNamespaceName() . '\\' . $match[1]);
            }
            foreach ($names as $name) {
                if (str_starts_with($name, 'OrillaCart\\') && class_exists($name) && !enum_exists($name)) {
                    $nested[] = $name;
                }
            }
        }

        return $nested;
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

    /** The first paragraph of the action's docblock. */
    private static function summary(string $action): string
    {
        if (!class_exists($action)) {
            return '';
        }
        $doc = (string) new \ReflectionClass($action)->getDocComment();
        $lines = [];
        foreach (explode("\n", $doc) as $line) {
            $line = trim(preg_replace(['#^\s*(/\*\*|\*/|\*)\s?#', '#\s*\*/$#'], '', $line) ?? '');
            if ($line === '' && $lines !== []) {
                break;
            }
            if ($line !== '' && !str_starts_with($line, '@')) {
                $lines[] = $line;
            }
        }

        return implode(' ', $lines);
    }

    /**
     * The DTOs the action maps the body and the query to, from its code: $this->input->body($request, X::class).
     *
     * @return array{?class-string, ?class-string}
     */
    private static function inputs(string $action): array
    {
        if (!class_exists($action)) {
            return [null, null];
        }
        $file = new \ReflectionClass($action)->getFileName();
        $code = \is_string($file) ? (string) file_get_contents($file) : '';
        $class = static function (string $kind) use ($code): ?string {
            if (preg_match('/->' . $kind . '\(\$request, (\w+)::class\)/', $code, $match) !== 1) {
                return null;
            }
            $name = preg_match('/^use ([\w\\\\]+\\\\' . $match[1] . ');$/m', $code, $use) === 1 ? $use[1] : null;

            return $name !== null && class_exists($name) ? $name : null;
        };

        return [$class('body'), $class('query')];
    }

    /**
     * @param class-string $dto
     *
     * @return list<array{name: string, type: string, rules: string}>
     */
    private static function fields(string $dto): array
    {
        $constructor = new \ReflectionClass($dto)->getConstructor();
        if ($constructor === null) {
            return [];
        }
        $documented = self::paramTypes((string) $constructor->getDocComment());
        $fields = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $documented[$parameter->getName()] ?? (string) $parameter->getType();
            $rules = array_values(array_filter(array_map(self::rule(...), $parameter->getAttributes()), static fn(string $rule): bool => $rule !== ''));
            if ($parameter->isDefaultValueAvailable()) {
                $default = $parameter->getDefaultValue();
                if ($default !== null && $default !== [] && $default !== '' && \is_scalar($default)) {
                    $rules[] = 'default ' . var_export($default, true);
                }
            } else {
                array_unshift($rules, 'required');
            }
            $fields[] = [
                'name' => strtolower((string) preg_replace(self::SNAKE, '_$0', $parameter->getName())),
                'type' => self::shortType($type),
                'rules' => implode('; ', $rules),
            ];
        }

        return $fields;
    }

    /**
     * @return array<string, string> the @param types of the docblock, by parameter name
     */
    private static function paramTypes(string $doc): array
    {
        preg_match_all('/@param\s+(\S+)\s+\$(\w+)/', $doc, $matches, PREG_SET_ORDER);
        $types = [];
        foreach ($matches as $match) {
            $types[$match[2]] = $match[1];
        }

        return $types;
    }

    /** "?OrillaCart\Ordering\Application\Dto\AddressData" reads "AddressData|null". */
    private static function shortType(string $type): string
    {
        $nullable = str_starts_with($type, '?');
        $short = (string) preg_replace('/(?:\w+\\\\)+(\w+)/', '$1', ltrim($type, '?'));

        return $nullable ? $short . '|null' : $short;
    }

    /**
     * A validation attribute in words: "at most 100 characters", "one of read, read_write".
     *
     * @param \ReflectionAttribute<object> $attribute
     */
    private static function rule(\ReflectionAttribute $attribute): string
    {
        $class = $attribute->getName();
        $slash = strrpos($class, '\\');
        $name = $slash === false ? $class : substr($class, $slash + 1);
        $args = $attribute->getArguments();
        $arg = static fn(string $key, int $position = 0): mixed => $args[$key] ?? $args[$position] ?? null;

        return match ($name) {
            'NotBlank' => 'not empty',
            'Positive' => 'more than 0',
            'PositiveOrZero' => '0 or more',
            'Email' => 'an email',
            'Url' => 'an address (URL)',
            'Date' => 'a date, Y-m-d',
            'Length' => self::range($arg('min'), $arg('max'), 'characters'),
            'Count' => self::range($arg('min'), $arg('max'), 'items'),
            'Range' => self::range($arg('min'), $arg('max'), ''),
            'Choice' => 'one of ' . self::listed(self::choices($arg('choices'), $args['callback'] ?? null)),
            'All' => self::each($arg('constraints')),
            'Regex' => \is_string($arg('pattern')) ? 'matches `' . $arg('pattern') . '`' : '',
            'Valid', 'Type' => '',
            default => strtolower((string) preg_replace(self::SNAKE, ' $0', $name)),
        };
    }

    /**
     * The values of a Choice: given, or what its callback answers.
     *
     * @return list<mixed>
     */
    private static function choices(mixed $choices, mixed $callback): array
    {
        if (\is_array($choices)) {
            return array_values($choices);
        }
        $values = \is_callable($callback) ? $callback() : null;

        return \is_array($values) ? array_values($values) : ['the known values'];
    }

    /**
     * @param list<mixed> $values
     */
    private static function listed(array $values): string
    {
        return implode(', ', array_map(static fn(mixed $value): string => \is_scalar($value) ? (string) $value : '', $values));
    }

    /** What each item of a list must be, from an All's constraints. */
    private static function each(mixed $constraints): string
    {
        $words = [];
        foreach (\is_array($constraints) ? $constraints : [$constraints] as $constraint) {
            $words[] = match (true) {
                $constraint instanceof \Symfony\Component\Validator\Constraints\Positive => 'more than 0',
                $constraint instanceof \Symfony\Component\Validator\Constraints\NotBlank => 'not empty',
                $constraint instanceof \Symfony\Component\Validator\Constraints\Length => self::range($constraint->min, $constraint->max, 'characters'),
                $constraint instanceof \Symfony\Component\Validator\Constraints\Choice => 'one of ' . self::listed(self::choices($constraint->choices, $constraint->callback)),
                default => '',
            };
        }
        $words = array_filter($words, static fn(string $word): bool => $word !== '');

        return $words === [] ? '' : 'each ' . implode(', ', $words);
    }

    private static function range(mixed $min, mixed $max, string $unit): string
    {
        $unit = $unit === '' ? '' : " {$unit}";

        return match (true) {
            \is_scalar($min) && \is_scalar($max) => "{$min} to {$max}{$unit}",
            \is_scalar($max) => "at most {$max}{$unit}",
            \is_scalar($min) => "at least {$min}{$unit}",
            default => '',
        };
    }
}
