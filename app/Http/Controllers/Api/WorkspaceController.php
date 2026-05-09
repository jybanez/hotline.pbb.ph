<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends BaseApiController
{
    public function userAccess(Request $request): JsonResponse
    {
        $this->authorizeWorkspaceRequest($request);

        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])
            ->first();

        return response()->json([
            'app_code' => 'hq',
            'has_access' => (bool) $user,
            'display_name' => 'PBB HQ',
            'roles' => $user ? [$user->role] : [],
            'embeddable' => true,
            'launch_path' => '/',
        ]);
    }

    private function authorizeWorkspaceRequest(Request $request): void
    {
        $expectedToken = trim((string) config('services.workspace.access_token', ''));
        $providedToken = trim((string) $request->bearerToken());

        if ($expectedToken === '' || $providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            abort(response()->json([
                'message' => 'Workspace token is invalid.',
            ], 401));
        }
    }
}
