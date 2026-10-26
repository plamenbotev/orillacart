<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Routing;

/**
 * One field a route's body or query takes, read from the constructor of the DTO the action maps it to.
 */
final readonly class InputField
{
    /**
     * @param string                             $name        as the client sends it, in snake_case
     * @param string                             $property    the constructor parameter
     * @param string                             $type        the docblock's type when it has one ("list<int>"), else PHP's ("?string")
     * @param bool                               $required    the parameter has no default
     * @param mixed                              $default     the default, when it has one
     * @param list<\ReflectionAttribute<object>> $rules       the validation attributes on the parameter
     * @param string                             $description the docblock's words after the parameter's name
     */
    public function __construct(
        public string $name,
        public string $property,
        public string $type,
        public bool $required,
        public mixed $default,
        public array $rules,
        public string $description,
    ) {}
}
