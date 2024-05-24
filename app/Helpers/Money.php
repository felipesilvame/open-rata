<?php

namespace App\Helpers;

class Money {
    public static function format($number, $currency = 'CLP') {
        return number_format($number, 0, ',', '.').' '.$currency;
    }
}