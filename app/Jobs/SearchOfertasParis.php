<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SearchOfertasParis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $categorias_tecno_70 = [
        'tecnologia',
        'electro',
        'lineablanca',
        'Ferreteria',
        'Construccion',
        'Outlet',

    ];

    public $categories_ropa_75 = [
        'dormitorio',
        'muebles',
        'decohogar',
        'modamujer',
        'modahombre',
        'belleza',
        'Regalos',
        'zapatos',
        'Herramientas',
        //'categorias',
        'deportes',

    ];

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ScrapParis::dispatch($this->categorias_tecno_70, 70, 3, config('rata.webhook_rata_tecno'));
        ScrapParis::dispatch($this->categories_ropa_75, 75, 2, config('rata.webhook_rata_ropa'));
    }
}
