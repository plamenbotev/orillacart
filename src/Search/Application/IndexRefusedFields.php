<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

/**
 * The index refused a document for fields it does not know: an index made by an earlier version of the shop,
 * whose mapping was not brought up to this one's. Preparing the index again and writing the batch again mends it.
 */
final class IndexRefusedFields extends \RuntimeException {}
