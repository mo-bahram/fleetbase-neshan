import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | map-settings', function (hooks) {
    setupTest(hooks);

    test('it loads server-backed settings', async function (assert) {
        class FetchStubService extends Service {
            get() {
                return Promise.resolve({
                    mapProvider: 'google',
                    googleMapsApiKey: 'abc123',
                    googleMapsMapType: 'satellite',
                    showGoogleMapsTrafficLayer: true,
                    showGoogleMapsTransitLayer: true,
                    neshanMapApiKey: 'neshan-map-key',
                    neshanMapType: 'standard-night',
                    fallbackMapProvider: 'neshan',
                });
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        const service = this.owner.lookup('service:map-settings');
        const settings = await service.load();

        assert.strictEqual(settings.mapProvider, 'google');
        assert.strictEqual(service.googleMapsApiKey, 'abc123');
        assert.strictEqual(service.googleMapsMapType, 'satellite');
        assert.true(service.showGoogleMapsTrafficLayer);
        assert.true(service.showGoogleMapsTransitLayer);
        assert.true(service.isGoogleMaps);
        assert.strictEqual(service.neshanMapApiKey, 'neshan-map-key');
        assert.strictEqual(service.neshanMapType, 'standard-night');
        assert.strictEqual(service.fallbackMapProvider, 'neshan');
    });

    test('it falls back safely when loading fails', async function (assert) {
        class FetchStubService extends Service {
            get() {
                return Promise.reject(new Error('failed'));
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        const service = this.owner.lookup('service:map-settings');
        const settings = await service.load();

        assert.strictEqual(settings.mapProvider, 'leaflet');
        assert.strictEqual(service.googleMapsApiKey, '');
        assert.strictEqual(service.googleMapsMapType, 'roadmap');
        assert.false(service.showGoogleMapsTrafficLayer);
        assert.false(service.showGoogleMapsTransitLayer);
        assert.false(service.isGoogleMaps);
        assert.strictEqual(service.neshanMapApiKey, '');
        assert.strictEqual(service.neshanMapType, 'dreamy');
    });

    test('it applies google view layer defaults when values are omitted', function (assert) {
        const service = this.owner.lookup('service:map-settings');
        const settings = service.applySettings({ mapProvider: 'google' });

        assert.strictEqual(settings.googleMapsMapType, 'roadmap');
        assert.false(service.showGoogleMapsTrafficLayer);
        assert.false(service.showGoogleMapsTransitLayer);
    });

    test('it identifies Neshan as a selectable map provider', function (assert) {
        const service = this.owner.lookup('service:map-settings');
        service.applySettings({ mapProvider: 'neshan' });

        assert.true(service.isNeshanMaps);
        assert.false(service.isGoogleMaps);
    });
});
