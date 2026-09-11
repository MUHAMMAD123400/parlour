<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ip;
use Exception;
use Illuminate\Http\Request;

class IpController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->per_page ?? 10;

            $query = Ip::query();

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('ip', 'like', '%' . $search . '%')
                        ->orWhere('title', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            }

            if ($request->has('status') && $request->status !== null && $request->status !== '') {
                $status = (int) $request->status;
                if (in_array($status, [0, 1], true)) {
                    $query->where('status', $status);
                }
            }

            $ips = $query->latest()->paginate($perPage);

            return \Helper::paginatedResponse($ips);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'ip'          => 'required|ip|unique:ips,ip',
                'title'       => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'status'      => 'nullable|in:0,1',
            ]);

            if (!isset($validated['status'])) {
                $validated['status'] = 1;
            }

            $ip = Ip::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'IP address added successfully.',
                'data'    => $ip,
            ], 201);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $ip = Ip::findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'IP address fetched successfully.',
                'data'    => $ip,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e, 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $ip = Ip::findOrFail($id);

            $validated = $request->validate([
                'ip'          => 'required|ip|unique:ips,ip,' . $ip->id,
                'title'       => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'status'      => 'nullable|in:0,1',
            ]);

            $ip->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'IP address updated successfully.',
                'data'    => $ip->fresh(),
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $ip = Ip::findOrFail($id);
            $ip->delete();

            return response()->json([
                'success' => true,
                'message' => 'IP address deleted successfully.',
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }

    /**
     * Toggle active status of the specified IP.
     */
    public function toggleStatus($id)
    {
        try {
            $ip = Ip::findOrFail($id);
            $ip->status = $ip->status == 1 ? 0 : 1;
            $ip->save();

            return response()->json([
                'success' => true,
                'message' => 'IP status updated successfully.',
                'data'    => $ip,
            ], 200);
        } catch (Exception $e) {
            return errorResponse($e);
        }
    }
}
