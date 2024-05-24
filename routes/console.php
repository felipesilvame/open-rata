<?php

use App\Jobs\SearchOfertasEntel;
use App\Jobs\SearchOfertasLapolar;
use App\Jobs\SearchOfertasLider;
use App\Jobs\SearchOfertasHites;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SearchOfertasEntel)->everyFourMinutes();
Schedule::job(new SearchOfertasLapolar)->everyTwoMinutes();
Schedule::job(new SearchOfertasLider)->everyTwoMinutes();
Schedule::job(new SearchOfertasHites)->everyTwoMinutes();