<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

/**
 * A job handler whose failed jobs stay until retried or deleted, instead of going after a month: what they left
 * undone matters until it is done, as an order the reports never counted.
 */
interface KeepsFailures extends JobHandler {}
