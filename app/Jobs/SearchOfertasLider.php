<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\ScrapLider;

class SearchOfertasLider implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $categorias_65 = [
        'Tecno/TV/Smart TV',
        'Tecno/Videojuegos',
        'Tecno/Fotografía',
        'Celulares/Celulares y Teléfonos',
        'Electrohogar',
    ];

    public array $categorias_70 = [
        'CYBER', 'Campañas', 'Destacados Mundo Lider'
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
        ScrapLider::dispatch($this->categorias_65, '65', 2, config('rata.webhook_rata_tecno'));
        ScrapLider::dispatch($this->categorias_70, '70', 3, config('rata.webhook_rata_tecno'));
    }
}
