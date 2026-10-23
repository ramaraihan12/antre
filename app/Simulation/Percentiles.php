<?php

namespace App\Simulation;

final class Percentiles
{
    /**
     * Nearest-rank percentile: the smallest value that at least p percent of the values are at or below.
     *
     * @param  non-empty-list<float>  $values
     */
    public static function of(array $values, float $p): float
    {
        sort($values);
        $rank = (int) ceil($p / 100 * count($values));

        return $values[max(0, min(count($values) - 1, $rank - 1))];
    }
}
