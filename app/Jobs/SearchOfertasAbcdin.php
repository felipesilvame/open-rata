<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\ScrapAbcdin;

class SearchOfertasAbcdin implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $categorias_tecno_65 = [
        'Tecnologia',
        'linea-blanca'
    ];

    public $categorias_ropa_65 = [
        'dormitorio',
        'muebles',
        'hogar',
        'otras-lineas',
        'zapatillas-3'
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
        ScrapAbcdin::dispatch($this->categorias_tecno_65, '65', 3, config('rata.webhook_rata_tecno'));
        ScrapAbcdin::dispatch($this->categorias_ropa_65, '65', 5, config('rata.webhook_rata_ropa'));
    }
}
