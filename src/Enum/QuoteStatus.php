<?php

namespace App\Enum;

enum QuoteStatus: string
{
    case PENDING   = 'pending';
    case ACCEPTED  = 'accepted';
    case EXPIRED   = 'expired';
    case CANCELLED = 'cancelled';
}
