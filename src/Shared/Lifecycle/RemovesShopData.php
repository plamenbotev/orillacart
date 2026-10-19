<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Lifecycle;

/**
 * A lifecycle listener whose uninstall removes the shop's data (its tables, settings, files, content, the
 * customers' role): run only when the shop asked for all its data to go with the plugin. Without that, deleting
 * the plugin keeps the data for when it comes back, and removes only what it can make again.
 */
interface RemovesShopData {}
