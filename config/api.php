<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Internal API Key
    |--------------------------------------------------------------------------
    |
    | Clave estática para autenticar peticiones de scripts externos, scrapers,
    | webhooks (ej. Maxun), cURL o Postman sin requerir inicio de sesión.
    |
    */

    'key' => env('API_KEY'),

];
