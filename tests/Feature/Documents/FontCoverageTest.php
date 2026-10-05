<?php

function fontU16(string $bytes, int $offset): int
{
    return unpack('n', substr($bytes, $offset, 2))[1];
}

function fontU32(string $bytes, int $offset): int
{
    return unpack('N', substr($bytes, $offset, 4))[1];
}

function fontHasGlyph(string $bytes, int $cmap, int $codepoint): bool
{
    $count = fontU16($bytes, $cmap + 2);
    $format12 = null;
    $format4 = null;
    for ($i = 0; $i < $count; $i++) {
        $record = $cmap + 4 + 8 * $i;
        $sub = $cmap + fontU32($bytes, $record + 4);
        $format = fontU16($bytes, $sub);
        if ($format === 12) {
            $format12 = $sub;
        } elseif ($format === 4) {
            $format4 = $sub;
        }
    }
    if ($format12 !== null) {
        $groups = fontU32($bytes, $format12 + 12);
        for ($i = 0; $i < $groups; $i++) {
            $group = $format12 + 16 + 12 * $i;
            if ($codepoint >= fontU32($bytes, $group) && $codepoint <= fontU32($bytes, $group + 4)) {
                return fontU32($bytes, $group + 8) + $codepoint - fontU32($bytes, $group) !== 0;
            }
        }
    }
    if ($format4 === null || $codepoint > 0xFFFF) {
        return false;
    }
    $segments = fontU16($bytes, $format4 + 6) / 2;
    $ends = $format4 + 14;
    $starts = $ends + 2 * $segments + 2;
    $deltas = $starts + 2 * $segments;
    $ranges = $deltas + 2 * $segments;
    for ($i = 0; $i < $segments; $i++) {
        if ($codepoint < fontU16($bytes, $starts + 2 * $i) || $codepoint > fontU16($bytes, $ends + 2 * $i)) {
            continue;
        }
        $range = fontU16($bytes, $ranges + 2 * $i);
        $glyph = $range === 0 ? $codepoint : fontU16($bytes,
            $ranges + 2 * $i + $range + 2 * ($codepoint - fontU16($bytes, $starts + 2 * $i)));
        if ($range !== 0 && $glyph === 0) {
            return false;
        }

        return (($glyph + fontU16($bytes, $deltas + 2 * $i)) & 0xFFFF) !== 0;
    }

    return false;
}

test('embedded Noto Naskh covers Arabic, Arabic Indic digits and Sorani letters', function (): void {
    $bytes = file_get_contents(resource_path('fonts/NotoNaskhArabic-Variable.ttf'));
    $tables = fontU16($bytes, 4);
    $cmap = null;
    for ($i = 0; $i < $tables; $i++) {
        $record = 12 + 16 * $i;
        if (substr($bytes, $record, 4) === 'cmap') {
            $cmap = fontU32($bytes, $record + 8);
            break;
        }
    }
    expect($cmap)->not->toBeNull();
    $points = array_merge(range(0x0621, 0x064A), range(0x0660, 0x0669),
        [0x0695, 0x06B5, 0x06C6, 0x06CE, 0x06D5, 0x06A4, 0x067E, 0x0686, 0x0698, 0x06AF]);
    foreach ($points as $point) {
        expect(fontHasGlyph($bytes, $cmap, $point))->toBeTrue();
    }
});
