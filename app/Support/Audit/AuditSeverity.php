<?php

namespace App\Support\Audit;

class AuditSeverity
{
    public const INFO = 'info';

    public const MODERATION_CHANGE = 'moderation_change';

    public const SEVERE_CHANGE = 'severe_change';

    public const CRITICAL = 'critical';

    public const VALUES = [
        self::INFO,
        self::MODERATION_CHANGE,
        self::SEVERE_CHANGE,
        self::CRITICAL,
    ];
}
