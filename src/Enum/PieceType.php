<?php

namespace App\Enum;

enum PieceType: string
{
    case Finished = 'finished';
    case Intermediate = 'intermediate';
    case RawMaterial = 'raw_material';
    case Purchased = 'purchased';
}
