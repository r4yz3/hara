<?php

namespace App\Support\Logging;

use App\Application\Commands\AuditLogger;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Last line of defence for spec 11.3: whatever a caller puts in the log
 * context, credentials and exact positions are replaced before the record is
 * written. Uses the same word list as the audit log.
 */
class RedactSensitiveContext implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $audit = new AuditLogger;

        return $record->with(
            context: $audit->redact($record->context),
            extra: $audit->redact($record->extra),
        );
    }
}
