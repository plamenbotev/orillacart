<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * Marks a field check or a price adjuster the shop cannot do without, such as a price list from an ERP that sets
 * every wholesale price. When one fails, checkout refuses the order and asks the customer to try again in a
 * moment, instead of taking the answer unchecked or the price unadjusted, as it does for the others.
 */
interface FailsClosed {}
