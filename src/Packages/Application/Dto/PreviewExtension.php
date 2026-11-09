<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: one of the extension's views rendered as the shop would render it, for whoever writes the extension
 * without a browser at hand (the assistant): a page, an admin page, a slot, or a route that answers HTML. The
 * handler runs as a manager opening the screen would run it.
 */
final readonly class PreviewExtension
{
    public const string PAGE = 'page';
    public const string ADMIN_PAGE = 'admin_page';
    public const string SLOT = 'slot';
    public const string ROUTE = 'route';

    /**
     * @param string               $name      the page's or route's path ("/tickets"), or the slot's name ("account_after"); for a route, "GET /path" names the method
     * @param array<string, mixed> $query     the query string a page or route would get
     * @param array<string, mixed> $body      the body a route would get
     * @param list<mixed>          $arguments what the storefront would pass a slot: [section, customer] for account_after
     */
    public function __construct(
        #[Assert\Choice(choices: [self::PAGE, self::ADMIN_PAGE, self::SLOT, self::ROUTE])]
        public string $kind,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $name,
        public array $query = [],
        public array $body = [],
        public array $arguments = [],
    ) {}
}
