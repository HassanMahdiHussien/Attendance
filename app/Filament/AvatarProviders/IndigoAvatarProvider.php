<?php

namespace App\Filament\AvatarProviders;

use App\Support\Format\Initials;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

class IndigoAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initials = htmlspecialchars(Initials::for((string) $record->name), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80">'
            .'<rect width="80" height="80" rx="40" fill="#26384F"/>'
            .'<text x="40" y="43" dominant-baseline="middle" text-anchor="middle" fill="#FFFFFF" font-family="Readex Pro,sans-serif" font-size="28" font-weight="700">'
            .$initials.'</text></svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }
}
