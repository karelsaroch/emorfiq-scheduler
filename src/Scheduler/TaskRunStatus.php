<?php

declare(strict_types=1);

namespace Emorfiq\Scheduler;

enum TaskRunStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Předchozí běh ještě běží (withoutOverlapping). */
    case SkippedOverlapping = 'skipped_overlapping';
    /** Tento výskyt už zpracoval jiný server (onOneServer). */
    case SkippedAlreadyHandled = 'skipped_already_handled';
    /** Úloha je vypnutá. */
    case SkippedDisabled = 'skipped_disabled';

    public function isSkipped(): bool
    {
        return str_starts_with($this->value, 'skipped_');
    }
}
