<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\SospechaRata;
use Illuminate\Support\Facades\Http;
use App\Helpers\Rata;
use App\Helpers\Arr as ArrHelper;

class ScrapParis implements ShouldQueue
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
        $this->uri = 'cl-ccom-parisapp-plp.ecomm.cencosud.com/v2/getServicePLP/0/[offset]/[limit]?refine_1=cgid%3D';
        $this->suffix = '&refine_2=isMarketplace%3DParis&sort=price-low-to-high';
        $this->page_start = 1;
        $this->total_pages = 1;
        $this->tienda = 'Paris';
        $this->title_field = 'product_name';
        $this->discount_field = '';
        $this->sku_field = 'product_id';
        $this->nombre_field = 'product_name';
        $this->precio_referencia_field = 'prices.clp-list-prices';
        $this->precio_oferta_field = 'prices.clp-internet-prices';
        $this->precio_tarjeta_field = 'prices.clp-cencosud-prices';
        $this->buy_url_field = '';
        $this->image_url_field = 'image.link';
        $this->headers= [
            'Apikey' => 'cl-ccom-parisapp-plp',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_2) AppleWebKit/601.3.9 (KHTML, like Gecko) Version/9.0.2 Safari/601.3.9',
            'Platform' => 'web',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("ScrapParis: Iniciando Job");
        usleep($this->start_delay * 1000000);
        $data = collect();
        $response = null;

        foreach ($this->categories as $category) {
            usleep($this->start_delay * 1000000);
            $offset = 0;
            $limit = 24;
            $url = $this->protocol . '://' . str_replace(['[offset]', '[limit]'], [$offset, $limit], $this->uri . $category . $this->suffix);
            try {
                $response = Http::withHeaders($this->headers)->get($url);
                if(!$response->ok()) {
                    Log::error("ScrapParis: Error al obtener la respuesta de la URL: $url");
                    return;
                }
            } catch (\Throwable $th) {
                Log::error("ScrapParis: Error al obtener la respuesta de la URL: $url");
                return;
            }
            // get total elements
            $total_elements = ArrHelper::get($response->json(), 'payload.total', 0);
            $this->total_pages = ceil($total_elements / $limit);
            try {
                // get json response
                $data = $data->concat($this->parseData($response->json()));
            } catch (\Throwable $th) {
                Log::error("ScrapParis: Error al obtener los productos de: $url");
            }
            // page now is 2
            $this->page_start = 2;
            

            for ($page = $this->page_start; $page <= $this->total_pages; $page++) {
                // wait like 300 - 400 ms
                usleep(rand(300, 400) * 1000);

                $offset = ($page - 1) * $limit;
                $url = $this->protocol . '://' . str_replace(['[offset]', '[limit]'], [$offset, $limit], $this->uri . $category . $this->suffix);
                try {
                    $response = Http::withHeaders($this->headers)->get($url);
                    if(!$response->ok()) {
                        Log::error("ScrapParis: Error al obtener la respuesta de la URL: $url");
                        continue;
                    }
                } catch (\Throwable $th) {
                    Log::error("ScrapParis: Error al obtener la respuesta de la URL: $url");
                    continue;
                }
                try {
                    // get json response
                    $data = $data->concat($this->parseData($response->json()));
                } catch (\Throwable $th) {
                    Log::error("ScrapParis: Error al obtener los productos de: $url");
                }
            }

            Log::info("ScrapParis: Productos encontrados: ".count($data));
            $data = $data->filter(function($item){
                return $item['descuento'] >= $this->discount;
            });

            Log::info("ScrapParis: Productos filtrados: ".count($data)." con descuento mayor a ".$this->discount."%");
            foreach ($data->values() as $item) {
                $sospecha = SospechaRata::where('sku', $item['sku'])->where('tienda', $item['tienda'])->first();
                if (!$sospecha) {
                    $sospecha = SospechaRata::create($item);
                    try {
                        Rata::sospechaRata($sospecha, $this->webhook, $this->discount);
                    } catch (\Throwable $th) {
                        Log::error("ScrapParis: Error al enviar sospecha de rata por discord. \n"+ $th->getMessage() + " \n"+ $th->getTraceAsString());
                    }
                }
            }
        }
    }

    /**
     * Parsea la data obteniendo los elementos
     * @param array $data
     * @return \Illuminate\Support\Collection
     */
    public function parseData(array $data): \Illuminate\Support\Collection
    {
        $results = collect();
        if (!isset($data['payload'])) {
            Log::warning("ScrapParis: Json response does not contain 'payload' key");
            return collect();
        }
        if (!isset($data['payload']['data'])) {
            Log::warning("ScrapParis: Json response does not contain 'payload.data' key");
            return collect();
        }
        if (!isset($data['payload']['data']['hits'])) {
            Log::warning("ScrapParis: Json response does not contain 'payload.data.hits' key");
            return collect();
        }
        foreach ($data['payload']['data']['hits'] as $item) {
            $nombre = '';$sku = '';$img = '';$p_normal = 0;$p_oferta = 0;$url = '';$descuento = 0;
            try {
                $nombre = ArrHelper::get_pipo($item, $this->nombre_field);
                $sku = ArrHelper::get_pipo($item, $this->sku_field);
                $url = 'https://www.paris.cl/'.$sku.'.html';
                $img = ArrHelper::get_pipo($item, $this->image_url_field);
            } catch (\Throwable $th) {
                //throw $th;
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
            try {
                $p_tarjeta = (integer)ArrHelper::get_pipo($item, $this->precio_tarjeta_field);
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
