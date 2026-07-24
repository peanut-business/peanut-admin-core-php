<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Logs;

final readonly class RuntimeLogEntry
{
    public function __construct(public StructuredLogRecord $record, public string $message) {}

    /** @return array<string, int|string|null> */
    public function toPublicArray(): array
    {
        return [
            'event_key' => $this->record->eventKey, 'severity' => $this->record->severity,
            'component_key' => $this->record->componentKey, 'message' => $this->message,
            'occurred_at' => $this->record->occurredAt, 'request_id' => $this->record->requestId,
            'occurrences' => $this->record->occurrences,
        ];
    }
}
