<?php

use Fleetbase\FleetOps\Support\NeshanClient;
use Illuminate\Support\Facades\Http;

test('neshan search uses service key and normalizes coordinate order', function () {
    Http::fake([
        'https://neshan.test/v3/search*' => Http::response([
            'items' => [[
                'title'        => 'Azadi Tower',
                'address'      => 'Azadi Square, Tehran',
                'region'       => 'Tehran',
                'neighbourhood' => 'Azadi',
                'location'     => ['x' => 51.3376, 'y' => 35.6997],
            ]],
        ]),
    ]);

    $results = (new NeshanClient('secret-key', 'https://neshan.test'))->search('Azadi', 35.7, 51.3);

    expect($results)->toHaveCount(1)
        ->and($results[0]['provider'])->toBe('neshan')
        ->and($results[0]['location']['coordinates'])->toBe([51.3376, 35.6997]);

    Http::assertSent(fn ($request) => $request->hasHeader('Api-Key', 'secret-key')
        && str_contains($request->url(), '/v3/search')
        && data_get(json_decode(data_get($request->data(), 'q'), true), 'term') === 'Azadi'
        && data_get(json_decode(data_get($request->data(), 'q'), true), 'center.latitude') === 35.7);
});

test('neshan geocoding supports address lookup without a search center', function () {
    Http::fake([
        'https://neshan.test/geocoding/v1*' => Http::response([
            'items' => [[
                'location'      => ['latitude' => 35.719934, 'longitude' => 51.340742],
                'province'      => 'Tehran',
                'city'          => 'Tehran',
                'neighbourhood' => 'Sadeghieh',
                'unMatchedTerm' => '',
            ]],
        ]),
    ]);

    $results = (new NeshanClient('secret-key', 'https://neshan.test'))->geocode('Tehran, Sattarkhan');

    expect($results)->toHaveCount(1)
        ->and($results[0]['street1'])->toBe('Tehran, Sattarkhan')
        ->and($results[0]['location']['coordinates'])->toBe([51.340742, 35.719934]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/geocoding/v1')
        && data_get(json_decode(data_get($request->data(), 'json'), true), 'address') === 'Tehran, Sattarkhan');
});

test('neshan reverse and direction responses are normalized', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/v5/reverse')) {
            return Http::response([
                'formatted_address' => 'Valiasr Street, Tehran',
                'route_name'        => 'Valiasr Street',
                'city'              => 'Tehran',
                'state'             => 'Tehran',
            ]);
        }

        return Http::response([
            'routes' => [[
                'overview_polyline' => ['points' => '_p~iF~ps|U_ulLnnqC'],
                'legs'              => [[
                    'distance' => ['value' => 1250],
                    'duration' => ['value' => 180],
                ]],
            ]],
        ]);
    });

    $client  = new NeshanClient('secret-key', 'https://neshan.test');
    $reverse = $client->reverse(35.7, 51.4);
    $route   = $client->route([[35.7, 51.4], [35.8, 51.5]]);

    expect($reverse[0]['location']['coordinates'])->toBe([51.4, 35.7])
        ->and($route['routes'][0]['overview_polyline']['points'])->toBe('_p~iF~ps|U_ulLnnqC')
        ->and($route['routes'][0]['legs'][0]['distance'])->toBe(1250.0)
        ->and($route['routes'][0]['legs'][0]['duration'])->toBe(180.0);
});

test('neshan client fails safely when unconfigured or upstream fails', function () {
    expect(fn () => (new NeshanClient('', 'https://neshan.test'))->search('Azadi', 35.7, 51.3))
        ->toThrow(RuntimeException::class, 'Neshan service is not configured.');

    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['*' => Http::response(['message' => 'internal detail'], 503)]);

    expect(fn () => (new NeshanClient('secret-key', 'https://failure.neshan.test'))->search('Azadi', 35.7, 51.3))
        ->toThrow(RuntimeException::class, 'Neshan service request failed.');
});
