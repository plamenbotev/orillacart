<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\OpenSearch;

/**
 * OpenSearch could not be reached or refused the request; the message says what it answered.
 */
final class OpenSearchUnavailable extends \RuntimeException {}
