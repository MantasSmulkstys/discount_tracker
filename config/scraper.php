<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Python interpreter
    |--------------------------------------------------------------------------
    | Override with SCRAPER_PYTHON if the worker host needs an absolute path
    | (e.g. a virtualenv binary).
    */
    'python' => env('SCRAPER_PYTHON', 'python'),

    /*
    |--------------------------------------------------------------------------
    | uscraper project directory
    |--------------------------------------------------------------------------
    | Absolute path to the sibling `uscraper/` repo where main.py lives.
    */
    'path' => env('SCRAPER_PATH', base_path('../uscraper')),

    /*
    |--------------------------------------------------------------------------
    | Subprocess timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('SCRAPER_TIMEOUT', 1800),
];
