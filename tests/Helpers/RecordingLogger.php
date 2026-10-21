<?php
/**
 * Logger double that records the messages of one level.
 *
 * Pest includes this file at boot, before Patchwork; that is fine for a
 * plain function nobody redefines.
 *
 * @package Tests\Helpers
 * @since   1.11.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Logger;

/**
 * Returns a logger mock that appends every message of the given level to
 * `$messages` and accepts all other calls.
 *
 * @param array<int,string> $messages Receives the messages, in call order.
 * @param string            $level    Logger method to record, 'info' or 'error'.
 *
 * @return Logger
 */
function recording_logger(array &$messages, string $level = 'info'): Logger {
    $logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
    $logger->shouldReceive($level)->andReturnUsing(function (string $prefix, string $message) use (&$messages) {
        $messages[] = $message;
    });

    return $logger;
}
