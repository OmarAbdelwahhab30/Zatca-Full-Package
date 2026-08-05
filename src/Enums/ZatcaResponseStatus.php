<?php

namespace Asas\Zatca\Enums;

enum ZatcaResponseStatus: string
{
    case Pass = 'pass';
    case Warning = 'warning';
    case Error = 'error';
    case Unknown = 'unknown';

    public static function fromClearanceStatus(?string $raw): self
    {
        return match (strtoupper((string) $raw)) {
            'PASS', 'REPORTED', 'CLEARED' => self::Pass,
            'WARNING', 'REPORTED_WITH_WARNINGS' => self::Warning,
            'ERROR', 'REJECTED' => self::Error,
            default => self::Unknown,
        };
    }
}
