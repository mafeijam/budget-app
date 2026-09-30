<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Symbol extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['symbol', 'name', 'source'];

    /**
     * Symbol => name, for the symbols given.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, string>
     */
    public static function namesFor(array $symbols): array
    {
        return $symbols === [] ? [] : self::whereIn('symbol', $symbols)->pluck('name', 'symbol')->all();
    }
}
