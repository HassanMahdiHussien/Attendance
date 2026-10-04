<?php

namespace App\Enums;

enum SignatureStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case RefusedToSign = 'refused_to_sign';
    case DeliveryRefused = 'delivery_refused';
}
