<?php

dataset('store logos', [
    'aibe' => 'aibe.png',
    'iki' => 'iki.jpg',
    'lidl' => 'lidl.png',
    'maxima' => 'maxima.png',
    'norfa' => 'norfa.png',
    'rimi' => 'rimi.png',
]);

test('store logo asset exists', function (string $filename) {
    expect(public_path("store-logos/{$filename}"))->toBeFile();
})->with('store logos');
