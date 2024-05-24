<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\ScrapLapolar;

class SearchOfertasLapolar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $categorias_65 = [
        'Tecnologia', 'linea-blanca'
    ];
    
    private $categorias_75 = [
        'Mujer',
        'Hombre',
        'muebles',
        'zapatillas',
        'zapatos',
        'belleza',
        'ninos',
        'deportes',
        'dormitorio', 
        'otras-lineas', 
        'hogar', 
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
        ScrapLapolar::dispatch($this->categorias_65, '65', 0, config('rata.webhook_rata_tecno'));
        ScrapLapolar::dispatch($this->categorias_75, '75', 4, config('rata.webhook_rata_ropa'));
    }
}
