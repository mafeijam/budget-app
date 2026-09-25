<?php

namespace App\Enums;

/**
 * The account statuses the UI offers in FormAccount.vue. See AccountType for
 * why this is an enum rather than a validated string.
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
