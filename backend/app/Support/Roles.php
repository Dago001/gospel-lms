<?php

namespace App\Support;

final class Roles
{
    public const STUDENT = 'student';
    public const LECTURER = 'lecturer';
    public const ADMIN = 'admin';
    public const FINANCE = 'finance';

    /** Roles that must enrol in multifactor authentication. */
    public const PRIVILEGED = [self::ADMIN, self::FINANCE];

    public const ALL = [self::STUDENT, self::LECTURER, self::ADMIN, self::FINANCE];
}
