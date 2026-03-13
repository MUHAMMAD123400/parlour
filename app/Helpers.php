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

/**
 * Global error response helper function
 * 
 * @param mixed $error Exception object or error message string
 * @param int $statusCode HTTP status code (default: 500)
 * @return \Illuminate\Http\JsonResponse
 */
if (!function_exists('errorResponse')) {
    function errorResponse($error, $statusCode = 500)
    {
        $message = 'Something went wrong';
        $errorMessage = '';

        if ($error instanceof \Exception) {
            $errorMessage = $error->getMessage();
            
            // Handle ModelNotFoundException specifically
            if ($error instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $statusCode = 404;
                $message = 'Resource not found';
            }
            
            // Handle ValidationException
            if ($error instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $error->errors()
                ], 422);
            }
        } elseif (is_string($error)) {
            $errorMessage = $error;
            $message = $error;
        }

        return response()->json([
            'message' => $message,
            'error' => $errorMessage
        ], $statusCode);
    }
}

?>
