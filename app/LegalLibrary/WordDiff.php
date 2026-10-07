<?php

namespace App\LegalLibrary;

class WordDiff
{
    public function diff(string $a, string $b): array
    {
        $left = preg_split('/\s+/u', trim($a), -1, PREG_SPLIT_NO_EMPTY);
        $right = preg_split('/\s+/u', trim($b), -1, PREG_SPLIT_NO_EMPTY);
        $n = count($left);
        $m = count($right);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $left[$i] === $right[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }
        $result = [];
        for ($i = 0, $j = 0; $i < $n || $j < $m;) {
            if ($i < $n && $j < $m && $left[$i] === $right[$j]) {
                $result[] = ['same', $left[$i]];
                $i++;
                $j++;
            } elseif ($i < $n && ($j === $m || $table[$i + 1][$j] >= $table[$i][$j + 1])) {
                $result[] = ['removed', $left[$i++]];
            } else {
                $result[] = ['added', $right[$j++]];
            }
        }

        return $result;
    }
}
