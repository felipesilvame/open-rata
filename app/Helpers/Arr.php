<?php

namespace App\Helpers;

use Illuminate\Support\ArrayAccess;
use Illuminate\Support\Arr as BaseArr;

/**
 *
 */
class Arr extends BaseArr
{
  /**
   * Get an item from an array using "dot" notation.
   *
   * @param  \ArrayAccess|array  $array
   * @param  string  $key
   * @param  mixed   $default
   * @return mixed
   */
  public static function get_pipo($array, $key, $default = null){
    if (! static::accessible($array)) {
        return value($default);
    }

    if (is_null($key)) {
        return $array;
    }

    if (static::exists($array, $key)) {
        return $array[$key];
    }

    if (strpos($key, '.') === false) {
        return $array[$key] ?? value($default);
    }

    foreach (explode('.', $key) as $segment) {
      if(count(explode(':', $segment)) == 2){
        if([$label, $theCondition]= explode(':', $segment)){
          if (count(explode(',', $theCondition)) == 3) {
            [$comparableKey, $comparableValue, $access] = explode(',',$theCondition);
            foreach($array[$label] as $key => $iterable){
              if(static::exists($iterable, $comparableKey) && $iterable[$comparableKey] == (string)$comparableValue){
                $array = $iterable[$access];
              }
            }
          } else return value($default);
        }
      }else if (static::accessible($array) && static::exists($array, $segment)) {
          $array = $array[$segment];
      } else {
          return value($default);
      }
    }

    return $array;
  }
}
