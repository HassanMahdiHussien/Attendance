<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->validate(['locale' => ['required', 'in:en,ar']])['locale'];

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        } else {
            $request->session()->put('locale', $locale);
        }

        return back();
    }
}
