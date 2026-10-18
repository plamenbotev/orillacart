<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * The provider would not refund; the message says why, for the shop.
 */
final class RefundRefused extends \RuntimeException {}
