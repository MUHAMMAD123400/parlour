<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        // Validate incoming request
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:6',
        ]);

        // Find the user
        $user = User::where('email', $request->email)->first();

        // Check password
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Check if user is active (status = '1')
        if ($user->status !== '1') {
            return response()->json([
                'message' => 'Your account is inactive. Please contact administrator.'
            ], 403);
        }

        // IP Restriction Check: Allow superadmin or users with permission to bypass IP restrictions
        // if (!($user->hasRole('superadmin') || $user->can('user.access.portal.anywhere'))) {
        //     $allowedIps = Ip::pluck('ip')->toArray();

        //     if (!in_array($request->ip(), $allowedIps)) {
        //         $ipInfo = $this->getIpLocation($request->ip());

        //         return response()->json([
        //             'ipSuccess' => false,
        //             'message' => 'Access Denied. Your IP address is not authorized.',
        //             'ip' => $request->ip(),
        //             'location' => [
        //                 'city' => $ipInfo['city'] ?? 'Unknown',
        //                 'region' => $ipInfo['region'] ?? 'Unknown',
        //                 'country' => $ipInfo['country'] ?? 'Unknown',
        //                 'timezone' => $ipInfo['timezone'] ?? 'Unknown',
        //                 'org' => $ipInfo['org'] ?? 'Unknown',
        //             ]
        //         ], 403);
        //     }
        // }

        // Delete expired tokens
        $user->tokens()->where('expires_at', '<', now())->delete();

        // Create a new token via Sanctum
        $tokenResult = $user->createToken('api-token');

        // Set token expiration manually (1 hour from now)
        $tokenResult->accessToken->expires_at = now()->addHour();
        $tokenResult->accessToken->save();

        $token = $tokenResult->plainTextToken;

        $user->load([
            'roles',
            'permissions',
            'company' => function ($q) {
                $q->with(['modules' => function ($mq) {
                    $mq->wherePivot('company_module_status', '1')->wherePivotNull('deleted_at');
                }]);
            },
        ]);

        // Get all permissions: direct permissions + permissions from roles
        $allPermissions = $user->getAllPermissions()->pluck('name');

        $companyPayload = null;
        if ($user->company_id && $user->relationLoaded('company') && $user->company) {
            $companyPayload = [
                'id' => $user->company->id,
                'company_name' => $user->company->company_name,
                'modules' => $user->company->modules->map(function ($m) {
                    return [
                        'id' => $m->id,
                        'module_name' => $m->module_name,
                        'permission_module_key' => $m->permission_module_key,
                    ];
                })->values(),
            ];
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'company_id' => $user->company_id,
                'company' => $companyPayload,
                'roles' => $user->getRoleNames(), // returns a collection of role names
                'permissions' => $allPermissions, // includes direct permissions + permissions from roles
            ],
            'token' => $token,
            'expires_at' => $tokenResult->accessToken->expires_at,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.'
        ]);
    }

    /**
     * Get IP location information from ipinfo.io
     *
     * @param string $ip
     * @return array
     */
    private function getIpLocation(string $ip): array
    {
        $url = 'https://ipinfo.io/' . $ip . '?token=bb2a9d9f5b81e4';
        $response = null;

        // Prefer cURL (works even if allow_url_fopen is Off)
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            // fallback if cURL is not available but allow_url_fopen is enabled
            if (ini_get('allow_url_fopen')) {
                $response = @file_get_contents($url);
            }
        }

        if ($response === false || empty($response)) {
            return [];
        }

        $data = json_decode($response, true);
        return $data ?? [];
    }
}
