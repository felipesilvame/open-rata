<?php

return [

    /**
     * webhook_rata_ropa es el webhook de discord para notificar sobre productos no necesarios
     */
    'webhook_rata_ropa' => env('DISCORD_WEBHOOK_RATA_ROPA', null),

    /**
     * webhook_rata_tecno es el webhook de discord para notificar sobre productos tecnologicos
     */
    'webhook_rata_tecno' => env('DISCORD_WEBHOOK_RATA_TECHNO', null),
];