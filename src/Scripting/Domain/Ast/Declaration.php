<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain\Ast;

/**
 * `kind word… "name" { props… } fn(…) {…}`: a payment gateway, a route, a setting block, an event handler. The
 * props are declarative values (literals, lists, maps, specs) and named functions; the handler is the function
 * that follows the block, where the kind takes one (`on`, `route`, `page`, `every`, `template_data`).
 */
final readonly class Declaration extends Node
{
    /**
     * @param list<string>            $words     the bare words between the kind and the name (`GET` in `route GET "/x"`)
     * @param array<string, Expr>     $props
     * @param array<string, FnExpr>   $functions by name
     * @param list<Declaration>       $nested
     */
    public function __construct(
        public string $kind,
        public array $words,
        public ?string $name,
        public array $props,
        public array $functions,
        public array $nested,
        public ?FnExpr $handler,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }
}
