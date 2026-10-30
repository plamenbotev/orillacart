<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Domain;

enum ImportStatus: string
{
    /** Uploaded and read: the shop manager looks at what it holds before starting. */
    case Ready = 'ready';
    /** A job is importing it, a batch of rows at a time. */
    case Running = 'running';
    case Done = 'done';
    /** Stopped before the end, by a fault the job could not get past: what was imported stays. */
    case Failed = 'failed';
}
