<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

/**
 * A step whose questions depend on the shop's country (its taxes, its law, where it ships): when the business
 * moves to another country, its answers are dropped, to be asked again as that country asks them.
 */
interface AskedByCountry {}
