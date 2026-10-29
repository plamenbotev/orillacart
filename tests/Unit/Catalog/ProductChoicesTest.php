<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\Dto\ProductChoice;
use OrillaCart\Catalog\Application\Dto\ProductChoiceQuery;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\ProductChoices;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Design 51: the admin's pickers offer each product once, by its own id, whatever language its name is found in.
 */
final class ProductChoicesTest extends TestCase
{
    public function testAProductIsFoundByItsNameInAnyLanguageAndOfferedOnceByItsOwnId(): void
    {
        $shop = new TestShop();
        $shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];
        $shop->content->add(10, 'Lamp');
        $shop->content->add(11, 'Lampe');
        $shop->content->add(20, 'Lampshade');
        $shop->contentLanguages->translate(['en' => 10, 'de' => 11]);
        $shop->products->save(10, new SaveProduct('40'));
        $shop->products->save(20, new SaveProduct('15'));
        $choices = new ProductChoices($shop->content, $shop->productTranslations);

        self::assertEquals([new ProductChoice(20, 'Lampshade'), new ProductChoice(10, 'Lamp')], $choices->find(new ProductChoiceQuery(search: 'lamp')), 'the German post is the product it translates, once');
        self::assertEquals([new ProductChoice(10, 'Lamp')], $choices->find(new ProductChoiceQuery(search: 'Lampe')), 'found by its German name');

        $shop->languageOfRequest->locale = 'de_DE';
        self::assertEquals([new ProductChoice(10, 'Lampe'), new ProductChoice(20, 'Lampshade')], $choices->find(new ProductChoiceQuery(include: '10,20')), 'named in the admin\'s language, by the product\'s id');
        self::assertSame([], $choices->find(new ProductChoiceQuery(search: ' ')));
    }
}
