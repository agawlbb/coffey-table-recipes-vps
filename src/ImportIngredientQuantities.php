<?php
declare(strict_types=1);

/** Conservative, local cleanup of familiar US quantities; no upstream request. */
final class ImportIngredientQuantities
{
    private const UNIT = '(?:cups?|tablespoons?|teaspoons?|tbsp\.?|tsp\.?|ounces?|oz\.?|pounds?|lbs?\.?)';

    public static function normalize(array $rows, string $system): array
    {
        $out = [];
        foreach ($rows as $row) {
            $line = trim((string)($row['original_text'] ?? ''));
            // "Each" is essential: never divide an unspecified combined amount.
            if (preg_match('~^(\d*\.\d+|\d+(?:\s+\d+/\d+|/\d+)?)\s+('.self::UNIT.')\s+(?:of\s+)?each\s+(?:of\s+)?(.+)$~iu', $line, $m)
                && !preg_match('/\b(?:or|plus|divided)\b|[,();]/iu', $m[3])) {
                $names = preg_split('/\s+and\s+|\s*&\s*/iu', trim($m[3]));
                if (count($names) >= 2 && count($names) <= 8 && !in_array('', $names, true)) {
                    foreach ($names as $name) {
                        $part = $row;
                        $part['ingredient_name'] = $name;
                        $part['original_text'] = $m[1].' '.$m[2].' '.$name;
                        $part['quantity_text'] = $m[1];
                        $part['original_unit'] = $m[2];
                        $part['quantity_max_decimal'] = null;
                        if (preg_match('~^(?:(\d+)\s+)?(\d+)/(\d+)$~', $m[1], $f) && (int)$f[3] > 0) {
                            $part['quantity_decimal'] = null;
                            $part['quantity_numerator'] = (int)($f[1] ?: 0) * (int)$f[3] + (int)$f[2];
                            $part['quantity_denominator'] = (int)$f[3];
                        } else {
                            $part['quantity_decimal'] = (string)(float)$m[1];
                            $part['quantity_numerator'] = $part['quantity_denominator'] = null;
                        }
                        $out[] = self::fraction($part, $system);
                    }
                    continue;
                }
            }
            $out[] = self::fraction($row, $system);
        }
        foreach ($out as $i => &$row) $row['id'] = 'new-ingredients-'.$i;
        unset($row);
        return $out;
    }

    private static function fraction(array $row, string $system): array
    {
        if ($system === 'metric' || !empty($row['quantity_max_decimal'])) return $row;
        $line = trim((string)($row['original_text'] ?? ''));
        if (!preg_match('~^(\d*\.\d+|\d+)\s+('.self::UNIT.')(\s+.+)$~iu', $line, $m)) return $row;
        $value = (float)$m[1];
        if ($value <= 0 || $value > 1000000) return $row;
        // Preserve conflicting structured quantities for normal review, not guessing.
        $exact = $row['quantity_decimal'] ?? null;
        if ($exact !== null && (!is_numeric($exact) || abs((float)$exact - $value) > 0.00005)) return $row;
        if (isset($row['quantity_numerator'], $row['quantity_denominator']) && (float)$row['quantity_denominator'] > 0
            && abs((float)$row['quantity_numerator'] / (float)$row['quantity_denominator'] - $value) > 0.00005) return $row;
        foreach ([1, 2, 3, 4, 8, 16] as $d) {
            $n = (int)round($value * $d);
            if (abs($n / $d - $value) > 0.00005) continue;
            $whole = intdiv($n, $d);
            $remainder = $n % $d;
            $quantity = $remainder ? ($whole ? $whole.' ' : '').$remainder.'/'.$d : (string)$whole;
            $unit = $m[2];
            if ($value <= 1) $unit = preg_replace('/s$/i', '', $unit);
            $row['original_text'] = $quantity.' '.$unit.$m[3];
            $row['quantity_text'] = $quantity;
            $row['original_unit'] = $unit;
            $row['quantity_decimal'] = $d === 1 ? (string)$n : null;
            $row['quantity_numerator'] = $d === 1 ? null : $n;
            $row['quantity_denominator'] = $d === 1 ? null : $d;
            return $row;
        }
        return $row;
    }
}
