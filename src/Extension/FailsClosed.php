<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * Marks a field check, a price adjuster, an order fee, a tax provider, a cart rule or an address check the shop
 * cannot do without, such as a price list from an ERP that sets every wholesale price or an age check the law
 * asks for. When one fails, checkout refuses the order and asks the customer to try again in a moment, instead of
 * taking the answer unchecked, the price unadjusted, the cart unchecked or the address unchecked, as it does for
 * the others. A cart rule's failure refuses the cart (it cannot be ordered, nothing can be added); an address
 * check's tells the customer the address cannot be checked right now.
 */
interface FailsClosed {}
