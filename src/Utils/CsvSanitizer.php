<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Utility for sanitizing CSV / Spreadsheet cell values against Formula Injection (CWE-1236).
 *
 * Prevents execution of spreadsheet formulas and DDE commands when exported CSV files
 * are opened in Microsoft Excel, LibreOffice Calc, Google Sheets, or Apple Numbers.
 */
class CsvSanitizer
{
    /**
     * Characters that trigger spreadsheet formula calculation or command execution when leading a cell.
     */
    public const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r", "\n", '%', '|'];

    /**
     * Sanitize a cell value for safe spreadsheet export.
     *
     * @param mixed $value The cell value to sanitize.
     * @param bool $allowNumeric If true, pure valid numeric values (e.g. 123, -50.00) are not prefixed.
     * @return string Safe cell string.
     */
    public static function sanitize(mixed $value, bool $allowNumeric = true): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $str = (string) $value;
        if ($str === '') {
            return '';
        }

        // Allow strictly valid pure numbers (e.g. "100", "-50.00", "0.25")
        // Values starting with '+' (e.g. "+123") or containing formulas (e.g. "-2+3") are NOT safe numbers.
        if ($allowNumeric && preg_match('/^-?\d+(\.\d+)?$/', trim($str))) {
            return $str;
        }

        // Check if raw string or trimmed string begins with a dangerous prefix character
        $trimmed = ltrim($str, " \t\r\n\v\0");
        if ($trimmed === '') {
            return $str;
        }

        $rawFirstChar = $str[0];
        $trimmedFirstChar = $trimmed[0];

        if (
            in_array($rawFirstChar, self::DANGEROUS_PREFIXES, true) ||
            in_array($trimmedFirstChar, self::DANGEROUS_PREFIXES, true)
        ) {
            // Neutralize by prepending a single quote (') so spreadsheet applications interpret it strictly as literal text.
            // If already prefixed with a single quote, preserve as is.
            if ($rawFirstChar === "'") {
                return $str;
            }
            return "'" . $str;
        }

        return $str;
    }
}
