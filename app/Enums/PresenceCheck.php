<?php

namespace App\Enums;

enum PresenceCheck: string
{
    case BoxFilled = 'box_filled';
    case BoxAppearsEmpty = 'box_appears_empty';
}
