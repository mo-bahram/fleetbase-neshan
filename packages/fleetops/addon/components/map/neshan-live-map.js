import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { guidFor } from '@ember/object/internals';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { buildDriverLiveMapContent, buildPlaceInfoWindowContent, buildPlaceTooltipContent, buildVehicleLiveMapContent } from '../../utils/live-map-card-content';

export default class MapNeshanLiveMapComponent extends Component {
    @service mapManager;
    id = guidFor(this);
    @tracked map = null;

    willDestroy() {
        super.willDestroy(...arguments);
        if (this.mapManager.providerName === 'neshan') {
            this.mapManager.destroyMap();
        }
    }

    @action async setupMap(element) {
        try {
            this.map = await this.mapManager.initializeMap(element, {
                provider: 'neshan',
                lat: this.args.latitude,
                lng: this.args.longitude,
                zoom: this.args.zoom,
                contextmenu: true,
            });
            this.mapManager.on('moveend', () => this.args.onViewportChanged?.());
            this.args.onLoad?.({ target: this.map, provider: 'neshan' });
            this.syncResources();
            debug('[NeshanLiveMap] Map initialised');
        } catch (error) {
            debug(`[NeshanLiveMap] Map initialisation failed: ${error.message}`);
            this.args.onError?.(error);
        }
    }

    @action syncResources() {
        if (!this.map || this.mapManager.providerName !== 'neshan') return;
        this.#syncPoints(this.args.drivers, 'driver');
        this.#syncPoints(this.args.vehicles, 'vehicle');
        this.#syncPoints(this.args.places, 'place');
        this.#syncServiceAreas();
    }

    #syncPoints(records = [], type) {
        for (const record of records ?? []) {
            const coords = this.#coordinates(record.location);
            if (!coords) continue;

            let marker = this.mapManager.getMarker(record.id);
            if (!marker) {
                const options = this.#markerOptions(record, type);
                marker = this.mapManager.addMarker(record.id, coords.lat, coords.lng, options);
                this.args[this.#callbackName(type, 'Added')]?.(record, { target: marker });
            } else {
                this.mapManager.updateMarkerPosition(record.id, coords.lat, coords.lng, false, 0);
                if (Number.isFinite(record.heading)) {
                    this.mapManager.setMarkerRotation(record.id, record.heading);
                }
            }
        }
    }

    #markerOptions(record, type) {
        if (type === 'driver') {
            return {
                iconUrl: record.vehicle_avatar ?? '/engines-dist/images/driver-marker.png',
                iconSize: [20, 20],
                title: record.name,
                popup: buildDriverLiveMapContent(record, true),
                tooltip: buildDriverLiveMapContent(record),
                tooltipOptions: { html: true },
                rotationAngle: record.heading,
                onClick: () => this.args.onDriverClicked?.(record),
            };
        }
        if (type === 'vehicle') {
            return {
                iconUrl: record.avatar_url ?? '/engines-dist/images/vehicle-marker.png',
                iconSize: [20, 20],
                title: record.displayName,
                popup: buildVehicleLiveMapContent(record, true),
                tooltip: buildVehicleLiveMapContent(record),
                tooltipOptions: { html: true },
                rotationAngle: record.heading,
                onClick: () => this.args.onVehicleClicked?.(record),
            };
        }
        return {
            iconUrl: record.avatar_url ?? '/engines-dist/images/building-marker.png',
            iconSize: [16, 16],
            title: record.address,
            popup: buildPlaceInfoWindowContent(record),
            tooltip: buildPlaceTooltipContent(record),
            tooltipOptions: { html: true },
            onClick: () => this.args.onPlaceClicked?.(record),
        };
    }

    #syncServiceAreas() {
        for (const serviceArea of this.args.serviceAreas ?? []) {
            this.#syncPolygon(serviceArea, this.args.onServiceAreaLayerAdded);
            for (const zone of serviceArea.zones ?? []) {
                this.#syncPolygon(zone, this.args.onZoneLayerAdd);
            }
        }
    }

    #syncPolygon(record, callback) {
        const coordinates = record.leafletCoordinates;
        if (!isArray(coordinates) || !coordinates.length || this.mapManager.getOverlay(record.id)) return;

        const polygon = this.mapManager.addPolygon(record.id, coordinates, {
            color: record.stroke_color ?? record.color ?? '#3388ff',
            fillColor: record.color ?? '#3388ff',
            fillOpacity: 0.2,
            tooltip: record.name,
        });
        this.mapManager.hideLayer(polygon);
        callback?.(record, { target: polygon });
    }

    #callbackName(type, suffix) {
        return `on${type.charAt(0).toUpperCase()}${type.slice(1)}${suffix}`;
    }

    #coordinates(location) {
        if (isArray(location?.coordinates)) {
            const [lng, lat] = location.coordinates;
            return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
        }
        if (isArray(location)) {
            const [lat, lng] = location;
            return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
        }
        return Number.isFinite(location?.latitude) && Number.isFinite(location?.longitude) ? { lat: location.latitude, lng: location.longitude } : null;
    }
}
