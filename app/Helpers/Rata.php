<?php

namespace App\Helpers;

use App\Models\SospechaRata;
use GuzzleHttp\Client;
use App\Helpers\General\Arr as ArrHelper;
use Notification;
use Illuminate\Support\Facades\Log;

class Rata
{
    /**
    * Set a placeholder for img notification
    */
    public static function imagenUrl($url){
        if (!$url || $url == '') return 'https://via.placeholder.com/150';
        return parse_url($url, PHP_URL_SCHEME) === null ? 'https:'. $url : $url;
    }

    /**
     * Checkea cual es el precio o monto mas bajo de los que tiene un producto
     * 
     */
    public static function menorValor(SospechaRata $producto){
        $valores = [];
        if ($producto->precio_normal) {
            $valores[] = $producto->precio_normal;
        }
        if ($producto->precio_oferta) {
            $valores[] = $producto->precio_oferta;
        }
        if ($producto->precio_tarjeta) {
            $valores[] = $producto->precio_tarjeta;
        }
        return min($valores);
    }

    /**
     * Manda una sospecha rata por discord
     */
    public static function sospechaRata(SospechaRata $producto, string $webhook_url, string $descuento = null){
        $imgUrl = self::imagenUrl($producto->img);
        if (!$webhook_url) {
            Log::warning("Se ha tratado de realizar una SospechaRata sin webhook URL");
            return;
        }
        try {
            $client = new Client();
            $response = $client->post($webhook_url, [
                'json' => [
                    'content' => 'Una rata sorprendida ha encontrado '.$producto->nombre.' con descuento mayor a '.$descuento.'% '.Money::format(self::menorValor($producto), 'CLP').' . Visita '.$producto->url_compra.' para corroborar la oferta',
                    'embeds' => [
                        [
                            "title" => $producto->nombre.' a tan sólo '.Money::format(self::menorValor($producto), 'CLP'),
                            "url" => $producto->url,
                            "color" => 2612178,
                            "fields" => [
                                [
                                    "name" => "Nombre producto",
                                    "value" => $producto->nombre,
                                    "inline" => true,
                                ],
                                [
                                    "name" => "Tienda",
                                    "value" => $producto->tienda,
                                    "inline" => true,
                                ],
                                [
                                    "name" => "Precio antes",
                                    "value" => Money::format($producto->precio_normal, 'CLP'),
                                ],
                                [
                                    "name" => "Precio ahora",
                                    "value" => Money::format(self::menorValor($producto), 'CLP'),
                                ]
                            ],
                            "image" => [
                                "url" => $imgUrl
                            ]
                        ]
                    ]
                ]
            ]);
        } catch (\Throwable $th) {
            //throw $th;
        }
    }

}