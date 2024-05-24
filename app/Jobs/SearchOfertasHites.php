<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\ScrapHites;

class SearchOfertasHites implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $categorias_70_tecno = [
        'celulares',
        'tecnologia',
        'electro-hogar',
    ];

    public array $categorias_70_ropa = [
        'dormitorio',
        'mujer',
        'hombre',
        'hogar',
        'nuevas-categorias',
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
        ScrapHites::dispatch($this->categorias_70_tecno, '70', 2, config('rata.webhook_rata_tecno'));
        ScrapHites::dispatch($this->categorias_70_ropa, '70', 3, config('rata.webhook_rata_ropa'));
    }
}
