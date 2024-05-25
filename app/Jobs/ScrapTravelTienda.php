<?php

namespace App\Jobs;

use App\Helpers\Arr;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use Symfony\Component\BrowserKit\HttpBrowser;
use App\Models\SospechaRata;
use App\Helpers\Rata;

class ScrapTravelTienda implements ShouldQueue
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
    private $per_page;

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
        $this->uri = 'tienda.travel.cl/ccstore/v1/assembler/pages/Default/osf/catalog/_/';
        $this->suffix = '?maxItems=999&offSet=0&sort=&Nrpp=999&No=0&Nr=AND(sku.availabilityStatus:INSTOCK)&Ns=sku.activePrice|0';
        $this->page_start = 1;
        $this->total_pages = 1;
        $this->per_page = 250;
        $this->tienda = 'Travel Tienda';
        $this->headers = [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_2) AppleWebKit/601.3.9 (KHTML, like Gecko) Version/9.0.2 Safari/601.3.9',
            'origin' => 'https://tienda.travel.cl',
            'referer' => 'https://tienda.travel.cl',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('ScrapTravelTienda: Iniciando job');
        $client = new Client();
        $crawler = null;
        $items = collect();
        $data = null;
        $payload = [
            'Nrpp' => $this->per_page,
            'No' => 0,
            'Nr' => 'AND(sku.availabilityStatus:INSTOCK)',
            'Ns' => 'sku.activePrice|0',
        ];
        $options = [
            'headers' => $this->headers,
            'query' => $payload
        ];
        foreach ($this->categories as $category){
            $url = $this->protocol . '://' . $this->uri . $category;
            usleep($this->start_delay * 1000000);
            try {
                Log::info("ScrapTravelTienda: Obteniendo la categoría $category");
                $response = $client->get($url, $options)->getBody()->getContents();
                $data = json_decode($response, true);
                if (!((boolean) $response))
                {
                    Log::error("ScrapTravelTienda: Error al obtener la categoría $category");
                    continue;
                }
            } catch (\Exception $e) {
                Log::error('ScrapTravelTienda: Error al obtener la respuesta de la tienda: ' . $e->getMessage());
            }
            $total_elements = Arr::get($data, 'results.totalNumRecs', 0);
            Log::info("ScrapTravelTienda: Total de elementos en la categoría $category: $total_elements");
            $total_pages = ceil($total_elements / $this->per_page);
            $page = 1;
            if (isset($data['results']) &&  isset($data['results']['records'])) {
                $items = $items->concat($this->parseData($data['results']['records']));
                Log::info("ScrapTravelTienda: Total records: " . count($data['results']['records']));
            }

            // Seguir con las siguientes paginas
            for($i = 2; $i <= $total_pages; $i++) {
                usleep($this->start_delay * 1000000);
                $payload['No'] = ($i) * $this->per_page;
                $options = [
                    'headers' => $this->headers,
                    'query' => $payload
                ];
                try {
                    Log::info("ScrapTravelTienda: Obteniendo la página $i de $total_pages");
                    $response = $client->get($url, $options)->getBody()->getContents();
                    if (!((boolean) $response))
                    {
                        Log::error("ScrapTravelTienda: Error al obtener la categoría $category");
                        continue;
                    }
                    $data = json_decode($response, true);
                    if (isset($data['results']) && isset($data['results']['records'])) {
                        $items = $items->merge($this->parseData($data['results']['records']));
                        Log::info("ScrapTravelTienda: Total records: " . count($items));
                    }
                } catch (\Exception $e) {
                    Log::error('ScrapTravelTienda: Error al obtener la respuesta de la tienda: ' . $e->getMessage());
                    break;
                }
            }

            Log::info("ScrapTravelTienda: Se obtuvieron " . count($items) . " productos de la categoría $category");
            $items = $items->filter(function($item){
                return $item['descuento'] >= $this->discount;
            });
            Log::info("ScrapTravelTienda: Se encontraron " . count($items) . " productos con descuento mayor o igual a $this->discount%");
            foreach ($items->values() as $item) {
                $sospecha = SospechaRata::where('sku', $item['sku'])->where('tienda', $item['tienda'])->first();
                if (!$sospecha) {
                    $sospecha = SospechaRata::create($item);
                    try {
                        Rata::sospechaRata($sospecha, $this->webhook, $this->discount);
                    } catch (\Throwable $th) {
                        Log::error("ScrapTravelTienda: Error al enviar sospecha de rata por discord. \n"+ $th->getMessage() + " \n"+ $th->getTraceAsString());
                    }
                }
            }

        }
    }

    /**
     * Parse data from json response
     * @param array $items
     * @return \Illuminate\Support\Collection
     */
    public function parseData($items)
    {
        $results = collect();
        foreach($items as $item) {
            $nombre = null;
            $sku = null;
            $img = null;
            $url = null;
            $precio_normal = 0;
            $precio_oferta = 0;
            $precio_tarjeta = 0;
            $descuento = 0;
            try {
                try {
                    $nombre = $item['attributes']['product.displayName'][0];
                } catch (\Throwable $th) {
                    //throw $th;
                }
                try {
                    $sku = $item['attributes']['sku.repositoryId'][0];
                } catch (\Throwable $th) {
                    //throw $th;
                }
                try {
                    $img = $item['attributes']['product.primaryFullImageURL'][0];
                    if ((boolean) $img) {
                        $img = 'https://tienda.travel.cl' . $img;
                    }
                } catch (\Throwable $th) {
                    //throw $th;
                }
                try {
                    $url = $item['attributes']['product.route'][0];
                    if ((boolean) $url) {
                        $url = 'https://tienda.travel.cl' . $url;
                    }
                } catch (\Throwable $th) {
                    //throw $th;
                }
                try {
                    $precio_normal = $item['records'][0]['attributes']['sku.listPrice'][0];
                } catch (\Throwable $th) {
                    //throw $th;
                }
                try {
                    $precio_oferta = $item['records'][0]['attributes']['sku.salePrice'][0];
                } catch (\Throwable $th) {
                    //throw $th;
                }
                $precio_tarjeta = null;
                if ($precio_normal && $precio_oferta) {
                    $descuento = round(($precio_normal - $precio_oferta) / $precio_normal * 100);
                }
                if($nombre && $sku && $precio_normal > 0) {
                    $results->push([
                        'nombre' => $nombre,
                        'sku' => $sku,
                        'tienda' => $this->tienda,
                        'url' => $url,
                        'img' => $img,
                        'precio_normal' => $precio_normal > 0 ? $precio_normal : null,
                        'precio_oferta' => $precio_oferta > 0 ? $precio_oferta : null,
                        'precio_tarjeta' => $precio_tarjeta,
                        'descuento' => $descuento,
                    ]);
                }
            } catch (\Throwable $th) {
                // Nothing
            }
        }
        Log::info("ScrapTravelTienda: Se obtuvieron " . count($results) . " productos de la tienda Travel Tienda");
        return $results;
    }
}
