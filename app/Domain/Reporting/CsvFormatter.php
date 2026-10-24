<?php

namespace App\Domain\Reporting;

final class CsvFormatter
{
    /**
     * Format hours/rates to Harvest-style: minimum 1 dp, no trailing zeros beyond that.
     * 1.00 → "1.0", 1.50 → "1.5", 0.25 → "0.25"
     */
    public static function hours(float $value): string
    {
        $s = rtrim(number_format($value, 2, '.', ''), '0');

        if (str_ends_with($s, '.')) {
            $s .= '0';
        }

        return $s;
    }

    /**
     * RFC 4180: quote a field if it contains comma, double-quote, CR, or LF.
     * Internal double-quotes are escaped by doubling.
     */
    public static function field(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') ||
            str_contains($value, "\r") || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * Free text for a spreadsheet: a value that starts with a character Excel or
     * Sheets treats as the start of a formula gets a leading single quote, so it is
     * shown as text instead of being run. Then quoted as field() does.
     */
    public static function text(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            $value = "'".$value;
        }

        return self::field($value);
    }
}
