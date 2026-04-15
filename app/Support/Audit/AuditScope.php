<?php

namespace App\Support\Audit;

class AuditScope
{
    public const GROUP = 'group';

    public const ADMIN = 'admin';

    public const SYSTEM = 'system';

    public const USER = 'user';

    public const CHARACTER = 'character';

    public const VALUES = [
        self::GROUP,
        self::ADMIN,
        self::SYSTEM,
        self::USER,
        self::CHARACTER,
    ];
}
