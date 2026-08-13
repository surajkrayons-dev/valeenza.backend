<?php

namespace App\Helpers;

class NumberHelper
{
    protected static $ones = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
        18 => 'Eighteen', 19 => 'Nineteen'
    ];

    protected static $tens = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
    ];

    // ✅ Converts amount (e.g. 1273.00) to Indian-format words (e.g. "One Thousand Two Hundred Seventy Three")
    public static function convertToWords($amount)
    {
        $amount = (float) $amount;
        $rupees = floor($amount);
        $paise = round(($amount - $rupees) * 100);

        $words = self::numberToWords((int) $rupees);

        if ($paise > 0) {
            $words .= ' and ' . self::numberToWords((int) $paise) . ' Paise';
        }

        return trim($words);
    }

    protected static function numberToWords($number)
    {
        if ($number == 0) {
            return 'Zero';
        }

        $number = (int) $number;
        $result = '';

        // Crore
        if ($number >= 10000000) {
            $result .= self::numberToWords(intdiv($number, 10000000)) . ' Crore ';
            $number %= 10000000;
        }

        // Lakh
        if ($number >= 100000) {
            $result .= self::numberToWords(intdiv($number, 100000)) . ' Lakh ';
            $number %= 100000;
        }

        // Thousand
        if ($number >= 1000) {
            $result .= self::numberToWords(intdiv($number, 1000)) . ' Thousand ';
            $number %= 1000;
        }

        // Hundred
        if ($number >= 100) {
            $result .= self::numberToWords(intdiv($number, 100)) . ' Hundred ';
            $number %= 100;
        }

        // Tens / Ones
        if ($number > 0) {
            if ($result !== '') {
                $result .= '';
            }

            if ($number < 20) {
                $result .= self::$ones[$number];
            } else {
                $result .= self::$tens[intdiv($number, 10)];
                if ($number % 10 > 0) {
                    $result .= ' ' . self::$ones[$number % 10];
                }
            }
        }

        return trim($result);
    }
}