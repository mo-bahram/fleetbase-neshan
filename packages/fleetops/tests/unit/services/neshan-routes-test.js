import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | neshan-routes', function (hooks) {
    setupTest(hooks);

    test('it proxies and normalizes a Neshan v3 route', async function (assert) {
        const service = this.owner.lookup('service:neshan-routes');
        let request;

        service.fetch = {
            post: async (path, payload) => {
                request = { path, payload };

                return {
                    routes: [
                        {
                            overview_polyline: { points: '_p~iF~ps|U_ulLnnqC_mqNvxq`@' },
                            legs: [
                                {
                                    distance: 1250,
                                    duration: 180,
                                },
                            ],
                        },
                    ],
                };
            },
        };

        const route = await service.computeRoute([
            [38.5, -120.2],
            [43.252, -126.453],
        ]);

        assert.strictEqual(request.path, 'neshan/route');
        assert.deepEqual(request.payload.waypoints, [
            [38.5, -120.2],
            [43.252, -126.453],
        ]);
        assert.strictEqual(route.engine, 'neshan');
        assert.deepEqual(route.coordinates, [
            [38.5, -120.2],
            [40.7, -120.95],
            [43.252, -126.453],
        ]);
        assert.deepEqual(route.summary, {
            totalDistance: 1250,
            totalTime: 180,
        });
        assert.strictEqual(route.legs[0].durationMillis, 180000);
    });

    test('it requires two valid waypoints', async function (assert) {
        const service = this.owner.lookup('service:neshan-routes');

        await assert.rejects(service.computeRoute([[35.7, 51.4]]), /At least 2 waypoints/);
    });
});
