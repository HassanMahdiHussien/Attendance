<?php

namespace App\Documents;

use Illuminate\Support\Str;

class BodyFormatter
{
    public function format(string $body): string
    {
        $html = Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $html = preg_replace('~<(?!/?(?:p|ul|ol|li|strong|br)\b)[^>]*>~iu', '', $html);

        return $html;
    }
}
