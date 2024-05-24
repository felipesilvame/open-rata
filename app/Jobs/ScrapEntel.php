<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\SospechaRata;
use Symfony\Component\BrowserKit\HttpBrowser;
use App\Helpers\Rata;
use App\Helpers\Arr as ArrHelper;

class ScrapEntel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private array $categories;
    private $discount;
    private int $start_delay;
    private $webhook;
    private $protocol;
    private $method;
    private $uri;
    private $suffix;
    private $page_start;
    private $total_pages;
    private $tienda;
    private $title_field;
    private $discount_field;
    private $sku_field;
    private $nombre_field;
    private $precio_referencia_field;
    private $precio_oferta_field;
    private $precio_tarjeta_field;
    private $buy_url_field;
    private $image_url_field;
    private $headers;

    /**
     * Create a new job instance.
     */
    public function __construct(array $categories, string $discount, int $delay, string $webhook)
    {
        $this->categories = $categories;
        $this->discount = $discount;
        $this->start_delay = $delay;
        $this->webhook = $webhook;
        $this->protocol = 'https';
        $this->method = 'GET';
        $this->uri = 'https://miportal.entel.cl/catalogo/celulares?No=0&Nrpp=1024&Ns=listPrice|0&contentPath=%2Fpages%2Fstorechilepp%2Fcatalogo%2Fcelulares&eIdx=8&sIdx=1&subPath=main[1]&format=json-rest';
        $this->suffix = '';
        $this->page_start = 1;
        $this->total_pages = 1;
        $this->tienda = 'Entel';
        $this->title_field = 'attributes.displayName.0';
        $this->discount_field = '';
        $this->sku_field = 'attributes.sku.0';
        $this->nombre_field = 'attributes.displayName.0';
        $this->precio_referencia_field = 'attributes.skuListPrice.0';
        $this->precio_oferta_field = 'attributes.productListPrice.0';
        $this->precio_tarjeta_field = '';
        $this->buy_url_field = 'attributes.seoUrl.0';
        $this->image_url_field = 'attributes.productImage.0';
        $this->headers = [
            'User-Agent' =>  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_2) AppleWebKit/601.3.9 (KHTML, like Gecko) Version/9.0.2 Safari/601.3.9',
            'origin' => 'https://www.entel.cl',
            'referer' => 'https://www.entel.cl',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("ScrapEntel: Iniciando Job");
        usleep($this->start_delay * 1000000);
        $client = new HttpBrowser();

        $data = collect();
        $url = $this->uri.$this->suffix;
        try {
            $client->request($this->method, $url, [], [], $this->headers);
            if ($client->getResponse()->getStatusCode() !== 200) {
                Log::error("ScrapEntel: Error al obtener la página");
                return;
            }
        } catch (\Throwable $th) {
            Log::error("ScrapEntel: Error al obtener la página");
            return;
        }
        try {
            // get json response
            $data = $this->parseData($client->getResponse()->toArray());
        } catch (\Throwable $th) {
            Log::error("ScrapEntel: Error al obtener los productos");
            throw $th;
        }

        Log::info("ScrapEntel: Productos encontrados: ".count($data));
        $data = $data->filter(function($item){
            return $item['descuento'] >= $this->discount;
        });
        Log::info("ScrapEntel: Productos filtrados: ".count($data)." con descuento mayor a ".$this->discount."%");
        foreach ($data->values() as $item) {
            $sospecha = SospechaRata::where('sku', $item['sku'])->where('tienda', $item['tienda'])->first();
            if (!$sospecha) {
                $sospecha = SospechaRata::create($item);
                try {
                    Rata::sospechaRata($sospecha, $this->webhook, $this->discount);
                } catch (\Throwable $th) {
                    Log::error("ScrapEntel: Error al enviar sospecha de rata por discord. \n"+ $th->getMessage() + " \n"+ $th->getTraceAsString());
                }
            }
        }
    }

    /**
     * Parse data from json response
     * @param array $json
     * @return \Illuminate\Support\Collection
     */
    public function parseData($json)
    {
        $results = collect();
        if (!isset($json['response'])) {
            Log::warning("ScrapEntel Json response does not contain 'response' key");
            return collect();
        }
        if (!isset($json['response']['records'])) {
            Log::warning("ScrapEntel Json response does not contain 'response.records' key");
            return collect();
        }
        foreach ($json['response']['records'] as $item) {
            $nombre = '';$sku = '';$img = '';$p_normal = 0;$p_oferta = 0;$url = '';$descuento = 0;$out_of_stock = false;
            try {
                $nombre = ArrHelper::get_pipo($item, $this->nombre_field);
                $sku = ArrHelper::get_pipo($item, $this->sku_field);
                $url = 'https://miportal.entel.cl'.ArrHelper::get_pipo($item, $this->buy_url_field);
                $img = 'https://miportal.entel.cl'.ArrHelper::get_pipo($item, $this->image_url_field);
                $_outOfStock = ArrHelper::get_pipo($item, 'attributes.inventoryStatus.0');
                if ($_outOfStock == 'OUT_OF_STOCK') {
                    $out_of_stock = true;
                    continue;
                }
            } catch (\Throwable $th) {
                continue;
            }
            try {
                $p_normal = (integer)ArrHelper::get_pipo($item, $this->precio_referencia_field);
            } catch (\Throwable $th) {
                //nothing
            }
            try {
                $p_oferta = (integer)ArrHelper::get_pipo($item, $this->precio_oferta_field);
            } catch (\Throwable $th) {
                //nothing
            }
            if ($p_normal && $p_oferta) {
                $descuento = round(($p_normal - $p_oferta) / $p_normal * 100);
            }
            if ($nombre && $sku && $p_normal > 0) {
                $results->push([
                    'nombre' => $nombre,
                    'img' => $img,
                    'url' => $url,
                    'sku' => $sku,
                    'url' => $url,
                    'tienda' => $this->tienda,
                    'precio_normal' => $p_normal > 0 ? $p_normal : null,
                    'precio_oferta' => $p_oferta > 0 ? $p_oferta : null,
                    'precio_tarjeta' => null, // no hay precio tarjeta en entel
                    'descuento' => $descuento,
                ]);
            }
        }
        return $results;
    }
}
