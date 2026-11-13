<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Channel tap: one JSON object per line, with sensitive context redacted
 * (backlog E-01). Applied to the `json` channel in config/logging.php.
 */
class UseStructuredLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if (! $monolog instanceof Monolog) {
            return;
        }

        $monolog->pushProcessor(new RedactSensitiveContext);

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, true, true);
                $handler->setFormatter($formatter);
            }
        }
    }
}
