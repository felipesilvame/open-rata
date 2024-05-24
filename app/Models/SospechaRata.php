<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SospechaRata extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'nombre',
        'sku',
        'tienda',
        'url',
        'img',
        'precio_normal',
        'precio_oferta',
        'precio_tarjeta',
        'descuento',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'precio_normal' => 'float',
        'precio_oferta' => 'float',
        'precio_tarjeta' => 'float',
    ];
}
