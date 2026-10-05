<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Setting;
use Geocoder\Provider\GoogleMaps\GoogleMaps;
use Geocoder\Provider\GoogleMapsPlaces\GoogleMapsPlaces;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Geocoder\StatefulGeocoder;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;

/**
 * Class Geocoding.
 *
 * Provides geocoding functionalities using Google Maps API.
 */
class Geocoding
{
    /**
     * Search radius in meters.
     */
    public const SEARCH_RADIUS = 2000;

    /**
     * Geocode a search query to get places.
     *
     * @param string     $searchQuery the query to search for
     * @param float|null $latitude    optional latitude for location-based search
     * @param float|null $longitude   optional longitude for location-based search
     *
     * @return Collection a collection of places
     *
     * @throws \Exception
     */
    public static function geocode(string $searchQuery, $latitude = null, $longitude = null): Collection
    {
        if (static::usesNeshan()) {
            try {
                return collect(static::neshanClient()->geocode(
                    $searchQuery,
                    is_numeric($latitude) ? (float) $latitude : null,
                    is_numeric($longitude) ? (float) $longitude : null,
                ))->map(fn (array $result) => static::makePlaceFromNeshanResult($result))->filter()->values();
            } catch (\Throwable) {
                // Preserve the existing Google path when Neshan is unavailable.
            }
        }

        $geocoder = static::makeGeocoder();

        try {
            if ($latitude && $longitude) {
                $geoResults = $geocoder->geocodeQuery(
                    GeocodeQuery::create($searchQuery)
                        ->withData('mode', GoogleMapsPlaces::GEOCODE_MODE_SEARCH)
                        ->withData('location', "$latitude, $longitude")
                        ->withData('radius', self::SEARCH_RADIUS)
                );
            } else {
                $geoResults = $geocoder->geocodeQuery(
                    GeocodeQuery::create($searchQuery)
                        ->withData('mode', GoogleMapsPlaces::GEOCODE_MODE_SEARCH)
                );
            }

            return collect($geoResults->all())->map(
                function ($googleAddress) {
                    return static::makePlaceFromGoogleAddress($googleAddress);
                }
            )->values();
        } catch (\Exception $e) {
            // Handle exceptions here or re-throw them to be handled elsewhere
            throw $e;
        }

        return collect();
    }

    /**
     * Perform a reverse geocoding query based on a search query and coordinates.
     *
     * @param string $searchQuery the query to search for
     * @param float  $latitude    latitude of the location
     * @param float  $longitude   longitude of the location
     *
     * @return Collection a collection of places
     *
     * @throws \Exception
     */
    public static function reverseFromQuery(string $searchQuery, $latitude, $longitude): Collection
    {
        if (empty($searchQuery)) {
            return collect();
        }

        if (empty($latitude) && empty($longitude)) {
            return collect();
        }

        if (static::usesNeshan()) {
            try {
                return collect(static::neshanClient()->search($searchQuery, (float) $latitude, (float) $longitude))
                    ->map(fn (array $result) => static::makePlaceFromNeshanResult($result))
                    ->filter()
                    ->values();
            } catch (\Throwable) {
                // Preserve the existing Google nearby-search path.
            }
        }

        $geocoder = static::makeGeocoder();

        try {
            $geoResults = $geocoder->reverseQuery(
                ReverseQuery::fromCoordinates($latitude, $longitude)
                    ->withData('mode', GoogleMapsPlaces::GEOCODE_MODE_NEARBY)
                    ->withData('keyword', $searchQuery)
                    ->withData('radius', self::SEARCH_RADIUS)
            );

            return collect($geoResults->all())->map(
                function ($googleAddress) {
                    return static::makePlaceFromGoogleAddress($googleAddress);
                }
            )->values();
        } catch (\Exception $e) {
            // Handle exceptions here or re-throw them to be handled elsewhere
            throw $e;
        }

        return collect();
    }

    /**
     * Perform a reverse geocoding query based on coordinates.
     *
     * @param float       $latitude    latitude of the location
     * @param float       $longitude   longitude of the location
     * @param string|null $searchQuery optional query to refine the search
     *
     * @return Collection a collection of places
     *
     * @throws \Exception
     */
    public static function reverseFromCoordinates($latitude, $longitude, ?string $searchQuery = null): Collection
    {
        if (empty($latitude) && empty($longitude)) {
            return collect();
        }

        if (static::usesNeshan()) {
            try {
                $results = $searchQuery
                    ? static::neshanClient()->search($searchQuery, (float) $latitude, (float) $longitude)
                    : static::neshanClient()->reverse((float) $latitude, (float) $longitude);

                return collect($results)
                    ->map(fn (array $result) => static::makePlaceFromNeshanResult($result))
                    ->filter()
                    ->values();
            } catch (\Throwable) {
                // Preserve the existing Google reverse-geocoding path.
            }
        }

        $geocoder = static::makeGeocoder();

        try {
            if ($searchQuery) {
                $geoResults = $geocoder->reverseQuery(
                    ReverseQuery::fromCoordinates($latitude, $longitude)
                        ->withData('mode', GoogleMapsPlaces::GEOCODE_MODE_NEARBY)
                        ->withData('keyword', $searchQuery)
                        ->withData('radius', self::SEARCH_RADIUS)
                );
            } else {
                $geoResults = $geocoder->reverseQuery(
                    ReverseQuery::fromCoordinates($latitude, $longitude)
                        ->withData('mode', GoogleMapsPlaces::GEOCODE_MODE_NEARBY)
                        ->withData('radius', self::SEARCH_RADIUS)
                );
            }

            return collect($geoResults->all())->map(
                function ($googleAddress) {
                    return static::makePlaceFromGoogleAddress($googleAddress);
                }
            )->values();
        } catch (\Exception $e) {
            // Handle exceptions here or re-throw them to be handled elsewhere
            throw $e;
        }

        return collect();
    }

    /**
     * Locate places based on a search query and coordinates.
     *
     * @param string $searchQuery the query to search for
     * @param float  $latitude    latitude of the location
     * @param float  $longitude   longitude of the location
     *
     * @return Collection a unique collection of places
     *
     * @throws \Exception
     */
    public static function locate(string $searchQuery, $latitude, $longitude): Collection
    {
        try {
            $reverseQueryResults = static::reverseFromCoordinates($latitude, $longitude, $searchQuery);
            $geodingQueryResults = static::geocode($searchQuery, $latitude, $longitude);
        } catch (\Exception $e) {
            // Handle exceptions here or re-throw them to be handled elsewhere
            throw $e;
        }

        return $reverseQueryResults->merge($geodingQueryResults)->unique('street1');
    }

    /**
     * Query places based on a search query and coordinates.
     *
     * @param string $searchQuery the query to search for
     * @param float  $latitude    latitude of the location
     * @param float  $longitude   longitude of the location
     *
     * @return Collection A unique collection
     *
     * @throws \Exception
     */
    public static function query(string $searchQuery, $latitude, $longitude): Collection
    {
        try {
            $reverseQueryResults = static::reverseFromQuery($searchQuery, $latitude, $longitude);
            $geodingQueryResults = static::geocode($searchQuery, $latitude, $longitude);
        } catch (\Exception $e) {
            // Handle exceptions here or re-throw them to be handled elsewhere
            throw $e;
        }

        return $reverseQueryResults->merge($geodingQueryResults)->unique('street1');
    }

    public static function canGoogleGeocode(): bool
    {
        return Utils::notEmpty(config('services.google_maps.api_key'));
    }

    public static function canNeshanGeocode(): bool
    {
        return static::usesNeshan() && static::neshanClient()->available();
    }

    public static function usesNeshan(?string $provider = null): bool
    {
        if (is_string($provider) && $provider !== '') {
            return strtolower($provider) === 'neshan';
        }

        try {
            $company = Setting::lookupFromCompany('fleet-ops.map-settings', []);
            $system  = Setting::lookup('fleet-ops.map-settings', []);

            return strtolower((string) data_get($company, 'mapProvider', data_get($system, 'mapProvider', ''))) === 'neshan';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The language geocoding results are returned in — Google's `language`
     * parameter, e.g. `en`, `ru`, `es`.
     */
    public static function getLocale(): string
    {
        // `?:` rather than a config() default: the settings table stores this
        // key, so it exists-but-empty on installs that never filled it in, and
        // config()'s default only applies when the key is absent entirely.
        return config('services.google_maps.locale') ?: env('GOOGLE_MAPS_LOCALE', 'en');
    }

    /**
     * The region requests are biased towards — Google's `region` parameter.
     * This is a ccTLD (`us`, `ru`, `sg`), not a language code, and is a
     * separate concern from the locale above.
     */
    public static function getRegion(): ?string
    {
        return config('services.google_maps.region') ?: env('GOOGLE_MAPS_REGION', 'us');
    }

    protected static function makeGeocoder(): object
    {
        // Allow an alternative geocoder (or test double) to be injected
        // through the container without constructing a live HTTP client.
        if (app()->bound('fleetops.geocoder')) {
            return app('fleetops.geocoder');
        }

        // Region biases which results rank highest; locale controls the
        // language they come back in. Applying both here covers every caller
        // rather than each construction site repeating them.
        $httpClient = new Client();
        $provider   = new GoogleMaps($httpClient, static::getRegion(), config('services.google_maps.api_key', env('GOOGLE_MAPS_API_KEY')));

        return new StatefulGeocoder($provider, static::getLocale());
    }

    protected static function makePlaceFromGoogleAddress($googleAddress): Place
    {
        return Place::createFromGoogleAddress($googleAddress);
    }

    protected static function neshanClient(): NeshanClient
    {
        return app()->bound(NeshanClient::class) ? app(NeshanClient::class) : new NeshanClient();
    }

    protected static function makePlaceFromNeshanResult(array $result): ?Place
    {
        $latitude  = data_get($result, 'latitude', data_get($result, 'location.coordinates.1'));
        $longitude = data_get($result, 'longitude', data_get($result, 'location.coordinates.0'));

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        return new Place([
            'name'         => data_get($result, 'name'),
            'street1'      => data_get($result, 'street1', data_get($result, 'address')),
            'street2'      => data_get($result, 'street2'),
            'city'         => data_get($result, 'city'),
            'province'     => data_get($result, 'province'),
            'postal_code'  => data_get($result, 'postal_code'),
            'neighborhood' => data_get($result, 'neighborhood'),
            'district'     => data_get($result, 'district'),
            'country'      => data_get($result, 'country', 'IR'),
            'location'     => new Point((float) $latitude, (float) $longitude),
            'meta'         => array_merge((array) data_get($result, 'meta', []), ['provider' => 'neshan']),
        ]);
    }
}
