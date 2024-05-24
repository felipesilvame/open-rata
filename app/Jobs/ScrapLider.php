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
use GuzzleHttp\Client;
use App\Helpers\Rata;
use App\Helpers\Arr as ArrHelper;

class ScrapLider implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $start_delay;
    private array $categories;
    private $method;
    private $protocol;
    private $uri;
    private $page_start;
    private $tienda;
    private $total_elements_field;
    private $per_page_field;
    private $discount_field;
    private $results_field;
    private $sku_field;
    private $nombre_field;
    private $precio_referencia_field;
    private $precio_oferta_field;
    private $precio_tarjeta_field;
    private $buy_url_field;
    private $image_url_field;
    private $current_page_field;
    private $client;
    private $discount;
    private $webhook;

    /**
     * Create a new job instance.
     */
    public function __construct(array $categories, string $discount, int $delay, string $webhook)
    {
        $this->categories = $categories;
        $this->discount = $discount;
        $this->start_delay = $delay;
        $this->protocol = 'https';
        $this->method = 'POST';
        $this->uri = 'https://apps.lider.cl/catalogo/bff/category';
        $this->page_start = 1;
        $this->tienda = null;
        $this->total_elements_field = '';
        $this->per_page_field = '';
        $this->discount_field = 'discount';
        $this->results_field = 'products';
        $this->current_page_field = '';
        $this->sku_field = 'sku';
        $this->nombre_field = 'displayName';
        $this->precio_referencia_field = 'price.BasePriceReference';
        $this->precio_oferta_field = 'price.BasePriceSales';
        $this->precio_tarjeta_field = 'price.BasePriceTLMC';
        $this->buy_url_field = 'https://www.lider.cl/catalogo/product/sku/';
        $this->image_url_field = 'images.defaultImage';
        $this->webhook = $webhook;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("ScrapLider: Iniciando Job");
        usleep($this->start_delay * 1000000);
        $client = new Client();
        $payload = [
            'page' => 1,
            'facets' => ["sold-by:Lider.cl"],
            'sortBy' => 'price_asc',
            'hitsPerPage' => 100
        ];
        $headers = [
            'tenant' => 'catalogo',
            'x-channel' => 'BuySmart'
        ];
        foreach($this->categories as $category) {
            Log::info("ScrapLider: Iniciando Scrap de Categoria: $category");
            $data = collect();
            usleep($this->start_delay * 1000000);
            $payload['categories'] = $category;
            $url = $this->uri;
            $options = [
                'json' => $payload,
                'headers' => $headers,
            ];
            try {
                $response = $client->post($url, $options)->getBody()->getContents();
                if (!((boolean) $response)) {
                    Log::error("ScrapLider: Error al obtener la página");
                    continue;
                }
            } catch (\Throwable $th) {
                Log::error("ScrapLider: Error al obtener la página");
                continue;
            }
            try {
                // get json response
                $json = json_decode($response, true);
                $total_elements = ArrHelper::get($json, 'nbHits', 0);
                $total_pages = ArrHelper::get($json, 'nbPages', 0);
                $page = ArrHelper::get($json, 'page', 0);
                if (isset($json['data']) && isset($json['data']['products'])) {
                    $data->concat($this->parseData($json['data']['products']));
                }

                for ($i = 2; $i < $total_pages; $i++) { 
                    Log::info("ScrapLider: Obteniendo página $i de $total_pages");
                    try {
                        usleep($this->start_delay * 1000000);
                        $payload['page'] = $i;
                        $client->request($this->method, $url, $payload, [], $headers);
                        if ($client->getResponse()->getStatusCode() !== 200) {
                            Log::error("ScrapLider: Error al obtener la página");
                            continue;
                        }
                        $json = $client->getResponse()->toArray();
                        if (isset($json['data']) && isset($json['data']['products'])) {
                            $data->concat($this->parseData($json['data']['products']));
                        }
                    } catch (\Throwable $th) {
                        break; // si hay error obteniendo la pagina, se termina el ciclo
                    }
                }
            } catch (\Throwable $th) {
                Log::error("ScrapLider: Error al obtener los productos");
                throw $th;
            }

            $data = $data->filter(function($item){
                return $item['descuento'] >= $this->discount;
            });

            Log::info("ScrapLider: Productos encontrados: ".count($data));

            foreach ($data->values() as $item) {
                $sospecha = SospechaRata::where('sku', $item['sku'])->where('tienda', $item['tienda'])->first();
                if (!$sospecha) {
                    $sospecha = SospechaRata::create($item);
                    try {
                        Rata::sospechaRata($sospecha, $this->webhook, $this->discount);
                    } catch (\Throwable $th) {
                        Log::error("ScrapLider: Error al enviar sospecha de rata por discord. \n"+ $th->getMessage() + " \n"+ $th->getTraceAsString());
                    }
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
        if (!isset($json)) {
            return $results;
        }
        foreach ($json as $item) {
            $nombre = null;
            $sku = null;
            $url = null;
            $img = null;
            $p_normal = 0;
            $p_oferta = 0;
            $p_tarjeta = 0;
            $discount = 0;
            try {
                $nombre = ArrHelper::get($item, $this->nombre_field, '');
                $sku = ArrHelper::get($item, $this->sku_field, '');
                $img = ArrHelper::get($item, $this->image_url_field, '');
                $url = $this->buy_url_field . $sku;
            } catch (\Throwable $th) {
                continue;
            }
            try {
                $p_normal = (integer)ArrHelper::get($item, $this->precio_referencia_field, 0);
            } catch (\Throwable $th) {
                //throw $th;
            }
            try {
                $p_oferta = (integer)ArrHelper::get($item, $this->precio_oferta_field, 0);
            } catch (\Throwable $th) {
                //throw $th;
            }
            try {
                $p_tarjeta = (integer)ArrHelper::get($item, $this->precio_tarjeta_field, 0);
            } catch (\Throwable $th) {
                //throw $th;
            }
            try {
                $descuento = ArrHelper::get($item, $this->discount_field, 0);
            } catch (\Throwable $th) {
                //throw $th;
            }
            if ($nombre && $sku && $p_normal > 0) {
                $results->push([
                    'nombre' => $nombre,
                    'img' => $img,
                    'url' => $url,
                    'sku' => $sku,
                    'precio_normal' => $p_normal > 0 ? $p_normal : null,
                    'precio_oferta' => $p_oferta > 0 ? $p_oferta : null,
                    'precio_tarjeta' => $p_tarjeta > 0 ? $p_tarjeta : null,
                    'descuento' => (int)$discount,
                    'tienda' => $this->tienda
                ]);
            }
        }
        return $results;
    }
}
