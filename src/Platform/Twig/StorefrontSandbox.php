<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use Twig\Sandbox\SecurityPolicy;

/**
 * What an extension's template may do on the storefront: the email policy's tags, filters and tests, the
 * storefront's functions, no methods and no properties. Strict: an unknown filter is refused, not ignored.
 */
final class StorefrontSandbox
{
    public const array FUNCTIONS = ['block', 'cycle', 'date', 'include', 'max', 'min', 'parent', 'range', '__', '_x', '_n', '_nx', 'prices_include_tax', 'price_suffix', 'slot', 'tax_number_label', 'shop_currencies', 'shopper_currency', 'api_url', 'json_data', 'asset'];

    public static function policy(): SecurityPolicy
    {
        $policy = new SecurityPolicy(EmailTwig::TAGS, EmailTwig::FILTERS, [], [], self::FUNCTIONS, EmailTwig::TESTS);
        $policy->setStrict(true);

        return $policy;
    }
}
