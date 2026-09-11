<?php

namespace App\Enums;

enum LeadSource: string
{
    case Web = 'web';
    case Referral = 'referral';
    case ColdCall = 'cold_call';
    case Event = 'event';
    case Other = 'other';
}
