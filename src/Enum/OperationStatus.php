<?php

namespace App\Enum;

enum OperationStatus: string
{
    case PENDING     = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED   = 'completed';
}
