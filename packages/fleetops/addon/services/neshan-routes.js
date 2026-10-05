import Service, { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import polyline from '@fleetbase/ember-core/utils/polyline';

export default class NeshanRoutesService extends Service {
    @service fetch;

    name = 'Neshan';

    async computeRoute(waypoints = [], options = {}) {
        const normalizedWaypoints = this.#normalizeWaypoints(waypoints);
        if (normalizedWaypoints.length < 2) {
            throw new Error('At least 2 waypoints are required to compute a route.');
        }

        const response = await this.fetch.post('neshan/route', {
            waypoints: normalizedWaypoints,
            type: options.type ?? 'car',
            avoidTrafficZone: options.avoidTrafficZone,
            avoidOddEvenZone: options.avoidOddEvenZone,
            alternative: options.alternative,
        });
        const route = response?.routes?.[0];
        const encodedPolyline = route?.overview_polyline?.points ?? route?.overview_polyline;

        if (!encodedPolyline) {
            throw new Error('Neshan route request returned no geometry.');
        }

        const coordinates = polyline.decode(encodedPolyline);
        const legs = (isArray(route.legs) ? route.legs : []).map((leg) => {
            const distanceMeters = Number(leg?.distance?.value ?? leg?.distance ?? 0);
            const durationSeconds = Number(leg?.duration?.value ?? leg?.duration ?? 0);

            return {
                ...leg,
                distanceMeters,
                durationMillis: durationSeconds * 1000,
            };
        });

        return {
            engine: 'neshan',
            waypoints: normalizedWaypoints,
            coordinates,
            bounds: this.#boundsFromCoordinates(coordinates),
            summary: {
                totalDistance: legs.reduce((total, leg) => total + leg.distanceMeters, 0),
                totalTime: legs.reduce((total, leg) => total + leg.durationMillis / 1000, 0),
            },
            legs,
            raw: response,
        };
    }

    #normalizeWaypoints(waypoints) {
        return (isArray(waypoints) ? waypoints : [])
            .map((waypoint) => {
                if (isArray(waypoint) && waypoint.length >= 2) {
                    return [Number(waypoint[0]), Number(waypoint[1])];
                }

                const latitude = Number(waypoint?.lat ?? waypoint?.latitude);
                const longitude = Number(waypoint?.lng ?? waypoint?.longitude);

                return [latitude, longitude];
            })
            .filter(([latitude, longitude]) => Number.isFinite(latitude) && Number.isFinite(longitude));
    }

    #boundsFromCoordinates(coordinates) {
        if (!coordinates.length) {
            return [
                [0, 0],
                [0, 0],
            ];
        }

        const latitudes = coordinates.map(([latitude]) => latitude);
        const longitudes = coordinates.map(([, longitude]) => longitude);

        return [
            [Math.min(...latitudes), Math.min(...longitudes)],
            [Math.max(...latitudes), Math.max(...longitudes)],
        ];
    }
}
