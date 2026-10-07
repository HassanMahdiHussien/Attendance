<?php

namespace App\Enums;

enum ImportMode: string
{
    case Auto = 'auto';
    case Text = 'text';
    case Ocr = 'ocr';
}
