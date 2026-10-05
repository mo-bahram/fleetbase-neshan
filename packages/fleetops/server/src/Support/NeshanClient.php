<?php

namespace Fleetbase\FleetOps\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class NeshanClient
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $baseUrl = null,
    ) {
        $this->apiKey ??= config('services.neshan.service_api_key');
        $this->baseUrl = rtrim($this->baseUrl ?? config('services.neshan.base_url', env('NESHAN_BASE_URL', 'https://api.neshan.org')), '/');
    }

    public function available(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    public function search(string $query, ?float $latitude = null, ?float $longitude = null): array
    {
        // Neshan Search v3 requires a center point. Address-only lookups use
        // the Geocoding API, which intentionally supports requests without one.
        if ($latitude === null || $longitude === null) {
            return $this->geocode($query);
        }

        $payload = $this->request('get', '/v3/search', [
            'q' => json_encode([
                'term'   => $query,
                'center' => [
                    'latitude'  => $latitude,
                    'longitude' => $longitude,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        return collect(Arr::get($payload, 'items', []))
            ->map(fn (array $item) => $this->normalizeSearchResult($item))
            ->filter()
            ->values()
            ->all();
    }

    public function geocode(string $query, ?float $latitude = null, ?float $longitude = null): array
    {
        $request = ['address' => $query];

        if ($latitude !== null && $longitude !== null) {
            $request['location'] = [
                'latitude'  => $latitude,
                'longitude' => $longitude,
            ];
        }

        $payload = $this->request('get', '/geocoding/v1', [
            'json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        return collect(Arr::get($payload, 'items', []))
            ->map(fn (array $item) => $this->normalizeGeocodingResult($item, $query))
            ->filter()
            ->values()
            ->all();
    }

    public function reverse(float $latitude, float $longitude): array
    {
        $payload = $this->request('get', '/v5/reverse', [
            'lat' => $latitude,
            'lng' => $longitude,
        ]);

        if (!Arr::get($payload, 'formatted_address') && !Arr::get($payload, 'address')) {
            return [];
        }

        return [$this->normalizeReverseResult($payload, $latitude, $longitude)];
    }

    /**
     * Neshan direction v3 expects latitude,longitude coordinate strings.
     *
     * @param array<int, array{0: float|int, 1: float|int}> $waypoints
     */
    public function route(array $waypoints, array $options = []): array
    {
        $coordinates = collect($waypoints)
            ->map(fn ($point) => is_array($point) && count($point) >= 2 ? [(float) $point[0], (float) $point[1]] : null)
            ->filter(fn ($point) => $point && $point[0] >= -90 && $point[0] <= 90 && $point[1] >= -180 && $point[1] <= 180)
            ->values();

        if ($coordinates->count() < 2) {
            throw new \RuntimeException('At least two valid waypoints are required.');
        }

        $params = [
            'type'        => Arr::get($options, 'type', 'car'),
            'origin'      => $coordinates->first()[0] . ',' . $coordinates->first()[1],
            'destination' => $coordinates->last()[0] . ',' . $coordinates->last()[1],
        ];

        if ($coordinates->count() > 2) {
            $params['waypoints'] = $coordinates->slice(1, -1)->map(fn ($point) => $point[0] . ',' . $point[1])->implode('|');
        }

        foreach (['avoidTrafficZone', 'avoidOddEvenZone', 'alternative'] as $option) {
            if (array_key_exists($option, $options)) {
                $params[$option] = filter_var($options[$option], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
            }
        }

        $payload = $this->request('get', '/v3/direction', $params);

        return [
            'routes' => collect(Arr::get($payload, 'routes', []))
                ->map(fn (array $route) => $this->normalizeRoute($route))
                ->values()
                ->all(),
        ];
    }

    protected function request(string $method, string $path, array $params): array
    {
        if (!$this->available()) {
            throw new \RuntimeException('Neshan service is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Api-Key' => $this->apiKey])
                ->connectTimeout(5)
                ->timeout(15)
                ->retry(2, 150, throw: false)
                ->send(strtoupper($method), $this->baseUrl . $path, ['query' => $params]);

            if (!$response->successful()) {
                throw new \RuntimeException('Neshan service request failed.');
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                throw new \RuntimeException('Neshan service returned an invalid response.');
            }

            return $payload;
        } catch (ConnectionException|RequestException $e) {
            report($e);
            throw new \RuntimeException('Neshan service is unavailable.', 0, $e);
        }
    }

    protected function normalizeSearchResult(array $item): ?array
    {
        $latitude  = Arr::get($item, 'location.y', Arr::get($item, 'lat'));
        $longitude = Arr::get($item, 'location.x', Arr::get($item, 'lng'));

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $title   = trim((string) Arr::get($item, 'title', ''));
        $address = trim((string) Arr::get($item, 'address', $title));

        return $this->place($title ?: $address, $address, (float) $latitude, (float) $longitude, [
            'city'         => Arr::get($item, 'region'),
            'neighborhood' => Arr::get($item, 'neighbourhood'),
            'type'         => Arr::get($item, 'type'),
            'category'     => Arr::get($item, 'category'),
        ]);
    }

    protected function normalizeGeocodingResult(array $item, string $query): ?array
    {
        $latitude  = Arr::get($item, 'location.latitude');
        $longitude = Arr::get($item, 'location.longitude');

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        return $this->place($query, $query, (float) $latitude, (float) $longitude, [
            'city'           => Arr::get($item, 'city'),
            'province'       => Arr::get($item, 'province'),
            'neighborhood'   => Arr::get($item, 'neighbourhood'),
            'unmatched_term' => Arr::get($item, 'unMatchedTerm'),
        ]);
    }

    protected function normalizeReverseResult(array $payload, float $latitude, float $longitude): array
    {
        $address = (string) Arr::get($payload, 'formatted_address', Arr::get($payload, 'address', ''));
        $name    = (string) Arr::get($payload, 'place', Arr::get($payload, 'route_name', $address));

        return $this->place($name ?: $address, $address, $latitude, $longitude, [
            'city'         => Arr::get($payload, 'city'),
            'province'     => Arr::get($payload, 'state'),
            'district'     => Arr::get($payload, 'municipality_zone'),
            'neighborhood' => Arr::get($payload, 'neighbourhood'),
            'route_name'   => Arr::get($payload, 'route_name'),
        ]);
    }

    protected function place(string $name, string $address, float $latitude, float $longitude, array $meta = []): array
    {
        return [
            'name'         => $name,
            'street1'      => $address,
            'street2'      => null,
            'city'         => Arr::pull($meta, 'city'),
            'province'     => Arr::pull($meta, 'province'),
            'postal_code'  => null,
            'neighborhood' => Arr::pull($meta, 'neighborhood'),
            'district'     => Arr::pull($meta, 'district'),
            'country'      => 'IR',
            'location'     => [
                'type'        => 'Point',
                'coordinates' => [$longitude, $latitude],
            ],
            'latitude'     => $latitude,
            'longitude'    => $longitude,
            'address'      => $address,
            'provider'     => 'neshan',
            'meta'         => array_filter($meta, fn ($value) => $value !== null && $value !== ''),
        ];
    }

    protected function normalizeRoute(array $route): array
    {
        $polyline = Arr::get($route, 'overview_polyline.points', Arr::get($route, 'overview_polyline'));

        return [
            'overview_polyline' => ['points' => $polyline],
            'legs'              => collect(Arr::get($route, 'legs', []))
                ->map(fn (array $leg) => array_merge($leg, [
                    'distance' => (float) Arr::get($leg, 'distance.value', Arr::get($leg, 'distance', 0)),
                    'duration' => (float) Arr::get($leg, 'duration.value', Arr::get($leg, 'duration', 0)),
                ]))
                ->values()
                ->all(),
        ];
    }
}
