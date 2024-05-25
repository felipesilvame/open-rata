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

class ScrapHites implements ShouldQueue
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
        $this->uri = 'www.hites.com/';
        $this->suffix = '/?prefn1=productoMKP&prefv1=Hites.com&srule=discount-off&sz=9999&start=0';
        $this->page_start = 1;
        $this->total_pages = 1;
        $this->tienda = 'Hites';
        $this->title_field = 'a.link.product-name--bundle';
        $this->discount_field = 'span.discount-badge';
        $this->sku_field = '';
        $this->nombre_field = 'a.link.product-name--bundle';
        $this->precio_referencia_field = 'span.price-item.list.strike-through.only-normal-price,span.price-item.list.strike-through';
        $this->precio_oferta_field = 'span.price-item.sales.strike-through,span.price-item.sales';
        $this->precio_tarjeta_field = 'span.price-item.hites-price';
        $this->buy_url_field = 'a.link.product-name--bundle';
        $this->image_url_field = 'img.tile-image';
        $this->headers = [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_2) AppleWebKit/601.3.9 (KHTML, like Gecko) Version/9.0.2 Safari/601.3.9',
            'origin' => 'https://www.hites.com',
            'referer' => 'https://www.hites.com',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("ScrapHites: Iniciando Job");
        $client = new HttpBrowser();
        $crawler = null;
        $items = null;

        foreach ($this->categories as $category) {
            usleep($this->start_delay * 1000000);
            $data = collect();
            $url = $this->protocol . '://' . $this->uri . $category . $this->suffix;
            try {
                $crawler = $client->request($this->method, $url, [], [], $this->headers);
                if ($client->getResponse()->getStatusCode() !== 200) {
                    Log::error("ScrapHites: Error al obtener la página de la categoría $category");
                    continue;
                }
            } catch (\Throwable $th) {
                Log::error("ScrapHites: Error al obtener la página de la categoría $category");
                continue;
            }
            try {
                $items = $crawler->filter('div.product-tile.js-product-tile-container');
            } catch (\Throwable $th) {
                Log::error("ScrapHites: Error al obtener los productos de la categoría $category");
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
                        $nombre = $node->filter($this->nombre_field)->first()->text();
                        $sku = $node->attr('data-pid');
                        $url = 'https://www.hites.com'.$node->filter($this->buy_url_field)->first()->attr('href');
                        $img = $node->filter($this->image_url_field)->first()->attr('src');
                    } catch (\Throwable $th) {
                        // nothing
                    }
                    try {
                        $p_normal = (integer)preg_replace('/[^0-9]/','', trim($node->filter($this->precio_referencia_field)->first()->filter('span.value')->first()->attr('content')));
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                    try {
                        $p_oferta = (integer)preg_replace('/[^0-9]/','', trim($node->filter($this->precio_oferta_field)->first()->filter('span.value')->first()->attr('content')));
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                    try {
                        $p_tarjeta = (integer)preg_replace('/[^0-9]/','', trim($node->filter($this->precio_tarjeta_field)->first()->text()));
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                    try {
                        if ($p_normal && $p_oferta) {
                            $discount = round(($p_normal - $p_oferta) / $p_normal * 100.0);
                        }
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                    if ($nombre && $sku && $img) {
                        return [
                            'nombre' => $nombre,
                            'img' => $img,
                            'url' => $url,
                            'sku' => $sku,
                            'precio_normal' => $p_normal > 0 ? $p_normal : null,
                            'precio_oferta' => $p_oferta > 0 ? $p_oferta : null,
                            'precio_tarjeta' => $p_tarjeta > 0 ? $p_tarjeta : null,
                            'descuento' => (int)$discount,
                        ];
                    } else {
                        return null;
                    }
                }));
                Log::info("ScrapHites: Se obtuvieron ".count($data)." productos de $this->tienda para la categoria $category");

                $filtered = $data->filter(function($item){
                    if ($item && isset($item['descuento'])) {
                        return $item['descuento'] >= $this->discount;
                    } else return false;
                });

                foreach($filtered->values() as $item){
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
                            Log::error("ScrapHites: Error al enviar la sospecha rata por discord. \n"+ $th->getMessage() + " \n"+ $th->getTraceAsString());
                            //throw $th;
                        }
                    }
                }
            } catch (\Throwable $th) {
                Log::error("ScrapHites: Error al procesar los productos de la categoría $category. \n"+ $th->getMessage() + " \n"+ $th->getTraceAsString());
                //throw $th;
            }
        }
    }
}
