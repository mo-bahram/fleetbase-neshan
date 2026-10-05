<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Support\NeshanClient;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NeshanController extends Controller
{
    public function search(Request $request, NeshanClient $client): JsonResponse
    {
        $validated = $request->validate([
            'query'     => ['required', 'string', 'max:255'],
            'latitude'  => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ]);

        return $this->respond(fn () => $client->search(
            $validated['query'],
            isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            isset($validated['longitude']) ? (float) $validated['longitude'] : null,
        ));
    }

    public function geocode(Request $request, NeshanClient $client): JsonResponse
    {
        return $this->search($request, $client);
    }

    public function reverse(Request $request, NeshanClient $client): JsonResponse
    {
        $validated = $request->validate([
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return $this->respond(fn () => $client->reverse(
            (float) $validated['latitude'],
            (float) $validated['longitude'],
        ));
    }

    public function route(Request $request, NeshanClient $client): JsonResponse
    {
        $validated = $request->validate([
            'waypoints'           => ['required', 'array', 'min:2'],
            'waypoints.*'         => ['required', 'array', 'size:2'],
            'waypoints.*.0'       => ['required', 'numeric', 'between:-90,90'],
            'waypoints.*.1'       => ['required', 'numeric', 'between:-180,180'],
            'type'                => ['nullable', 'in:car,motorcycle'],
            'avoidTrafficZone'    => ['nullable', 'boolean'],
            'avoidOddEvenZone'    => ['nullable', 'boolean'],
            'alternative'         => ['nullable', 'boolean'],
        ]);

        return $this->respond(
            fn () => $client->route($validated['waypoints'], $validated),
            false,
        );
    }

    protected function respond(callable $callback, bool $wrapResults = true): JsonResponse
    {
        try {
            $payload = $callback();

            return response()->json($wrapResults ? ['results' => $payload] : $payload);
        } catch (\Throwable $e) {
            Log::warning('Neshan upstream request failed.', ['exception' => $e]);

            return response()->json([
                'error' => 'Neshan service is unavailable.',
                'code'  => 'NESHAN_UPSTREAM_UNAVAILABLE',
            ], 502);
        }
    }
}
