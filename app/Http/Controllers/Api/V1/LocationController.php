<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Governortate;
use App\Models\Region;
use App\Models\School;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    /**
     * GET /api/v1/governorates
     *
     * Public endpoint used by the registration screen.
     */
    public function governorates(): JsonResponse
    {
        $governorates = Governortate::query()
            ->select('id', 'name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $governorates,
        ]);
    }

    /**
     * GET /api/v1/governorates/{id}/regions
     *
     * Return only regions that belong to the selected governorate.
     */
    public function regions(int $id): JsonResponse
    {
        $governorate = Governortate::query()
            ->select('id', 'name')
            ->find($id);

        if (! $governorate) {
            return response()->json([
                'status' => false,
                'message' => 'Governorate not found.',
            ], 404);
        }

        $regions = Region::query()
            ->where('governortate_id', $governorate->id)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $regions,
        ]);
    }

    /**
     * GET /api/v1/regions/{id}/schools
     *
     * Return only schools that belong to the selected region.
     */
    public function schools(int $id): JsonResponse
    {
        $region = Region::query()
            ->select('id', 'name')
            ->find($id);

        if (! $region) {
            return response()->json([
                'status' => false,
                'message' => 'Region not found.',
            ], 404);
        }

        $schools = School::query()
            ->where('region_id', $region->id)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $schools,
        ]);
    }
}
