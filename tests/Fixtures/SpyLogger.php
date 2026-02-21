<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Psr\Log\AbstractLogger;

final class SpyLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    private array $logs = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return array<int, array{level: string, message: string, context: array}>
     */
    public function all(): array
    {
        return $this->logs;
    }

    public function hasLogThatContains(string $level, string $messageSubstring): bool
    {
        foreach ($this->logs as $log) {
            if ($log['level'] === $level && str_contains($log['message'], $messageSubstring)) {
                return true;
            }
        }
        return false;
    }
}
