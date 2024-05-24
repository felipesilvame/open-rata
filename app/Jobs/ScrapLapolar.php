<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\BrowserKit\HttpBrowser;
use App\Models\SospechaRata;
use App\Helpers\Rata;

use function Laravel\Prompts\warning;

class ScrapLapolar implements ShouldQueue
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
        $this->uri = 'www.lapolar.cl/on/demandware.store/Sites-LaPolar-Site/es_CL/Search-UpdateGrid?cgid=';
        $this->suffix = '&srule=price-low-to-high&start=0&sz=9999';
        $this->page_start = 1;
        $this->total_pages = 1;
        $this->tienda = 'La Polar';
        $this->title_field = 'div.product-tile__item';
        $this->discount_field = 'p.promotion-badge';
        $this->sku_field = 'div.product-tile__wrapper';
        $this->nombre_field = 'a.link[itemprop=url]';
        $this->precio_referencia_field = 'p.price.js-normal-price';
        $this->precio_oferta_field = 'p.price.js-internet-price';
        $this->precio_tarjeta_field = 'p.price.js-tlp-price';
        $this->buy_url_field = 'div.product-tile__wrapper a.image-link';
        $this->image_url_field = 'div.product-tile__wrapper img.tile-image';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("ScrapLapolar: Iniciando Job");
        usleep($this->start_delay * 1000000);
        $client = new HttpBrowser();
        $crawler = null;

        foreach ($this->categories as $category) {
            usleep($this->start_delay * 1000000);
            $data = collect();
            $url = $this->protocol . '://' . $this->uri . $category . $this->suffix;
            try {
                $crawler = $client->request($this->method, $url);
                if ($client->getResponse()->getStatusCode() !== 200) {
                    Log::error("ScrapLapolar: Error al obtener la página de la categoría $category");
                    continue;
                }
            } catch (\Throwable $th) {
                Log::error("ScrapLapolar: Error al obtener la página de la categoría $category");
                continue;
            }
            try {
                $items = $crawler->filter($this->title_field);
            } catch (\Throwable $th) {
                Log::error("ScrapLapolar: Error al obtener los productos de la categoría $category");
                throw $th;
            }
            try {
                $data = collect($items->each(function($node){
                    $nombre = null;
                    $img = null;
                    $url = null;
                    $sku = null;
                    $p_normal = null;
                    $p_oferta = null;
                    $p_tarjeta = null;
                    $discount = 0;
                    try {
                        $nombre = $node->filter($this->nombre_field)->first()->attr('data-product-name');
                        $sku = $node->filter($this->sku_field)->attr('data-pid');
                        $url = 'https://www.lapolar.cl'.$node->filter($this->buy_url_field)->first()->attr('href');
                        $img = $node->filter($this->image_url_field)->first()->attr('src');
                    } catch (\Throwable $th) {
                        //nothing
                    }
                    try {
                        $p_normal = (integer)preg_replace('/[^0-9]/','', trim($node->filter($this->precio_referencia_field)->first()->text()));
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                    try {
                        $p_oferta = (integer)preg_replace('/[^0-9]/','', trim($node->filter($this->precio_oferta_field)->first()->text()));
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                    try {
                        $p_tarjeta = (integer)preg_replace('/[^0-9]/','', trim($node->filter($this->precio_tarjeta_field)->first()->text()));
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                    try {
                        $discount = preg_replace("/[^0-9]/", "", $node->filter($this->discount_field)->first()->text());
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                    if ($nombre && $sku && $p_normal && $img) {
                        $res = [
                            'nombre' => $nombre,
                            'img' => $img,
                            'url' => $url,
                            'sku' => $sku,
                            'precio_normal' => $p_normal > 0 ? $p_normal : null,
                            'precio_oferta' => $p_oferta > 0 ? $p_oferta : null,
                            'precio_tarjeta' => $p_tarjeta > 0 ? $p_tarjeta : null,
                            'descuento' => (int)$discount,
                        ];
                        return $res;
                    } else {
                        //Log::warning("ScrapLapolar: No se pudo obtener la información de un producto. Nombre: $nombre, SKU: $sku, Precio normal: $p_normal, Url: $url");
                        return null;
                    }
                }));
                Log::info("ScrapLapolar: Se obtuvieron ".count($data)." productos de $this->tienda para la categoria $category");

                // filtrar por descuento 

                $filtered = $data->filter(function($item){
                    if ($item && isset($item['descuento'])) {
                        return $item['descuento'] >= $this->discount;
                    } else return false;
                });

                foreach($filtered->values() as $item) {
                    $sospecha = SospechaRata::where('sku', $item['sku'])->where("tienda", $this->tienda)->first();
                    if (!$sospecha) {
                        $sospecha = SospechaRata::create([
                            'nombre' => $item['nombre'],
                            'sku' => $item['sku'],
                            'tienda' => $this->tienda,
                            'url' => $item['url'],
                            'img' => $item['img'],
                            'precio_normal' => $item['precio_normal'],
                            'precio_oferta' => $item['precio_oferta'],
                            'precio_tarjeta' => $item['precio_tarjeta'],
                            'descuento' => $item['descuento'],
                        ]);
                        try {
                            Rata::sospechaRata($sospecha, $this->webhook, $this->discount);
                        } catch (\Throwable $th) {
                            Log::error("ScrapLapolar: Error al enviar la sospecha rata por discord. \n"+ $th->getMessage() + " \n"+ $th->getTraceAsString());
                            //throw $th;
                        }
                    }
                }
            } catch (\Throwable $th) {
                Log::error("ScrapLapolar: Error al procesar los productos de la categoría $category. \n"+ $th->getMessage() + " \n"+ $th->getTraceAsString());
                //throw $th;
            }
        }
    }
}
