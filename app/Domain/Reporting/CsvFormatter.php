<?php

namespace App\Domain\Reporting;

final class CsvFormatter
{
    /** First characters a spreadsheet reads as the start of a formula. */
    private const FORMULA_TRIGGERS = "=+-@\t\r";

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
        if ($value !== '' && str_contains(self::FORMULA_TRIGGERS, $value[0])) {
            $value = "'".$value;
        }

        return self::field($value);
    }

    /**
     * Reverse of text() for a value read back out of a CSV cell: drops the
     * single quote text() added. A quote before anything else is left alone.
     */
    public static function fromText(string $value): string
    {
        if (strlen($value) > 1 && $value[0] === "'" && str_contains(self::FORMULA_TRIGGERS, $value[1])) {
            return substr($value, 1);
        }

        return $value;
    }
}
