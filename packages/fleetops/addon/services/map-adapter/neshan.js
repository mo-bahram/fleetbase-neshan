import LeafletAdapter from './leaflet';
import loadNeshanSdk from '../../utils/neshan-sdk-loader';

export default class NeshanAdapter extends LeafletAdapter {
    async initializeMap(element, options = {}) {
        const L = await loadNeshanSdk();
        if (this._map) {
            return this._map;
        }

        this._map = new L.Map(element, {
            key: options.apiKey,
            maptype: options.mapType ?? 'dreamy',
            center: [options.lat ?? 35.6892, options.lng ?? 51.389],
            zoom: options.zoom ?? 12,
            zoomControl: options.zoomControl ?? false,
            contextmenu: options.contextmenu ?? true,
            contextmenuWidth: options.contextmenuWidth ?? 140,
            poi: options.poi ?? true,
            traffic: options.traffic ?? false,
            ...options.leafletOptions,
        });

        return this._map;
    }
}
