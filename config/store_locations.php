<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Kainų seklys — Store branch locations
|--------------------------------------------------------------------------
|
| Sample physical branches for each chain the platform tracks. Coordinates
| are approximate (WGS84 lat/lng) for well-known store locations across
| major Lithuanian cities. Keep this file as the single source of truth for
| the cart map — extend or replace with DB-backed data later if needed.
|
| Each entry shape:
|   - chain:   slug matching StoreId on the frontend (maxima, lidl, iki, ...)
|   - name:    human label shown on the marker / list row
|   - address: street + number
|   - city:    Lithuanian city name
|   - lat:     latitude  (decimal degrees)
|   - lng:     longitude (decimal degrees)
|
*/

return [
    ['chain' => 'maxima', 'name' => 'Maxima XXX Akropolis',      'address' => 'Ozo g. 25',              'city' => 'Vilnius',    'lat' => 54.7200, 'lng' => 25.2636],
    ['chain' => 'maxima', 'name' => 'Maxima X Gedimino',         'address' => 'Gedimino pr. 16',        'city' => 'Vilnius',    'lat' => 54.6892, 'lng' => 25.2784],
    ['chain' => 'maxima', 'name' => 'Maxima XX Fabijoniškės',    'address' => 'S. Stanevičiaus g. 41',  'city' => 'Vilnius',    'lat' => 54.7258, 'lng' => 25.2250],
    ['chain' => 'maxima', 'name' => 'Maxima XXX Mega',           'address' => 'Islandijos pl. 32',      'city' => 'Kaunas',     'lat' => 54.9043, 'lng' => 23.9783],
    ['chain' => 'maxima', 'name' => 'Maxima XX Akropolis',       'address' => 'Taikos pr. 61',          'city' => 'Klaipėda',   'lat' => 55.6869, 'lng' => 21.1527],

    ['chain' => 'lidl',   'name' => 'Lidl Justiniškės',          'address' => 'Justiniškių g. 62A',     'city' => 'Vilnius',    'lat' => 54.7188, 'lng' => 25.2049],
    ['chain' => 'lidl',   'name' => 'Lidl Šeškinė',              'address' => 'Ukmergės g. 234',        'city' => 'Vilnius',    'lat' => 54.7212, 'lng' => 25.2485],
    ['chain' => 'lidl',   'name' => 'Lidl Naujamiestis',         'address' => 'Vytenio g. 18',          'city' => 'Vilnius',    'lat' => 54.6792, 'lng' => 25.2641],
    ['chain' => 'lidl',   'name' => 'Lidl Dainava',              'address' => 'Partizanų g. 63B',       'city' => 'Kaunas',     'lat' => 54.9138, 'lng' => 23.9654],
    ['chain' => 'lidl',   'name' => 'Lidl Klaipėda',             'address' => 'Šilutės pl. 19',         'city' => 'Klaipėda',   'lat' => 55.6769, 'lng' => 21.1655],

    ['chain' => 'iki',    'name' => 'IKI Pilaitė',               'address' => 'Pilaitės pr. 17',        'city' => 'Vilnius',    'lat' => 54.7063, 'lng' => 25.1700],
    ['chain' => 'iki',    'name' => 'IKI Ogmios',                'address' => 'Verkių g. 29',           'city' => 'Vilnius',    'lat' => 54.7106, 'lng' => 25.2708],
    ['chain' => 'iki',    'name' => 'IKI Centras',               'address' => 'Vilniaus g. 13',         'city' => 'Vilnius',    'lat' => 54.6836, 'lng' => 25.2823],
    ['chain' => 'iki',    'name' => 'IKI Eiguliai',              'address' => 'Ukmergės g. 25',         'city' => 'Kaunas',     'lat' => 54.9294, 'lng' => 23.9278],
    ['chain' => 'iki',    'name' => 'IKI Panevėžys',             'address' => 'Klaipėdos g. 143',       'city' => 'Panevėžys',  'lat' => 55.7247, 'lng' => 24.3610],

    ['chain' => 'rimi',   'name' => 'Rimi Hyper Nordika',        'address' => 'Ukmergės g. 282',        'city' => 'Vilnius',    'lat' => 54.7266, 'lng' => 25.2555],
    ['chain' => 'rimi',   'name' => 'Rimi Katedros',             'address' => 'Katedros a. 4',          'city' => 'Vilnius',    'lat' => 54.6858, 'lng' => 25.2878],
    ['chain' => 'rimi',   'name' => 'Rimi Hyper Molas',          'address' => 'K. Baršausko g. 66A',    'city' => 'Kaunas',     'lat' => 54.9102, 'lng' => 23.9797],
    ['chain' => 'rimi',   'name' => 'Rimi Klaipėda',             'address' => 'Taikos pr. 61',          'city' => 'Klaipėda',   'lat' => 55.6869, 'lng' => 21.1527],
    ['chain' => 'rimi',   'name' => 'Rimi Šiauliai',             'address' => 'Aido g. 8',              'city' => 'Šiauliai',   'lat' => 55.9436, 'lng' => 23.3236],

    ['chain' => 'norfa',  'name' => 'Norfa XL Antakalnis',       'address' => 'Antakalnio g. 36',       'city' => 'Vilnius',    'lat' => 54.7005, 'lng' => 25.3115],
    ['chain' => 'norfa',  'name' => 'Norfa Karoliniškės',        'address' => 'L. Asanavičiūtės g. 3',  'city' => 'Vilnius',    'lat' => 54.6902, 'lng' => 25.2225],
    ['chain' => 'norfa',  'name' => 'Norfa XL Šilainiai',        'address' => 'Baltų pr. 151',          'city' => 'Kaunas',     'lat' => 54.9343, 'lng' => 23.8790],
    ['chain' => 'norfa',  'name' => 'Norfa Klaipėda',            'address' => 'Tilžės g. 61',           'city' => 'Klaipėda',   'lat' => 55.7133, 'lng' => 21.1417],
    ['chain' => 'norfa',  'name' => 'Norfa Panevėžys',           'address' => 'Nemuno g. 72',           'city' => 'Panevėžys',  'lat' => 55.7297, 'lng' => 24.3758],

    ['chain' => 'aibe',   'name' => 'Aibė Žirmūnai',             'address' => 'Žirmūnų g. 68',          'city' => 'Vilnius',    'lat' => 54.7148, 'lng' => 25.2911],
    ['chain' => 'aibe',   'name' => 'Aibė Naujininkai',          'address' => 'Dzūkų g. 25',            'city' => 'Vilnius',    'lat' => 54.6633, 'lng' => 25.2700],
    ['chain' => 'aibe',   'name' => 'Aibė Petrašiūnai',          'address' => 'R. Kalantos g. 110',     'city' => 'Kaunas',     'lat' => 54.8875, 'lng' => 24.0155],
    ['chain' => 'aibe',   'name' => 'Aibė Klaipėda',             'address' => 'Minijos g. 2',           'city' => 'Klaipėda',   'lat' => 55.7053, 'lng' => 21.1242],
    ['chain' => 'aibe',   'name' => 'Aibė Alytus',               'address' => 'Santaikos g. 36',        'city' => 'Alytus',     'lat' => 54.3924, 'lng' => 24.0480],
];
