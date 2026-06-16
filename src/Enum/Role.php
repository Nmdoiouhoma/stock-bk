<?php

namespace App\Enum;

enum Role: string
{
    case Admin = 'admin';
    case Worker = 'worker';
    case Customer = 'customer';
    case Seller = 'seller';
    case Supervisor = 'supervisor';
}
