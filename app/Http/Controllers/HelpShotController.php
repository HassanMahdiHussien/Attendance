<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class HelpShotController
{
    public function __invoke(string $locale, string $name): Response
    {
        $manifest = require resource_path('help/screenshots.php');
        abort_unless(in_array($locale, ['en', 'ar'], true) && array_key_exists($name, $manifest), 404);
        $file = resource_path("help/shots/{$locale}/{$name}.webp");
        abort_unless(is_file($file), 404);

        return response(file_get_contents($file), 200, ['Content-Type' => 'image/webp']);
    }
}
