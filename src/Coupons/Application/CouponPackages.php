<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application;

use OrillaCart\Coupons\Domain\CouponRepository;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationPackageSource;

/** A coupon's description for the translation service: shoppers read it on the cart, the receipt and the promotion blocks. */
final readonly class CouponPackages implements TranslationPackageSource
{
    public const string KIND = 'coupon';
    /** Coupons read at a time, registering them all. */
    private const int PAGE = 200;

    public function __construct(private CouponRepository $coupons) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(): string
    {
        return 'OrillaCart coupon';
    }

    public function package(int $id): ?TranslationPackage
    {
        $coupon = $this->coupons->find($id);
        if ($coupon === null) {
            return null;
        }

        return TranslationPackage::of(self::KIND, $id, $coupon->description(), [
            new PackageString(CouponTranslations::TYPE, $id, 'description', $coupon->description(), Text::of('Description')),
        ]);
    }

    public function ids(): iterable
    {
        foreach ([false, true] as $automatic) {
            for ($page = 1; ; ++$page) {
                [$coupons, $total] = $this->coupons->search(null, $page, self::PAGE, $automatic);
                foreach ($coupons as $coupon) {
                    if (trim($coupon->description()) !== '') {
                        yield $coupon->id();
                    }
                }
                if ($page * self::PAGE >= $total) {
                    break;
                }
            }
        }
    }
}
