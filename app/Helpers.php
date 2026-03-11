<?php

class Helper{

    /**
     * Standardized pagination response for API v2
     *
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator
     * @param array $totals Optional totals/statistics to include
     * @return \Illuminate\Http\JsonResponse
     */
    public static function paginatedResponse($paginator, $totals = [])
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'totals' => $totals,
        ]);
    }
}

?>
