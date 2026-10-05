<?php

namespace App\Enums;

enum LawKind: string
{
    case Statute = 'statute';
    case InternalRegulation = 'internal_regulation';
}
