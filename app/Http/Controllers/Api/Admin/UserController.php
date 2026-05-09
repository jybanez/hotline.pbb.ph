<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Enums\UserStatus;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Support\Admin\BlockedDeleteInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private readonly BlockedDeleteInspectorService $blockedDeletes,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $items = User::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search): void {
                    $nested
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $user = User::query()->create([
            'name' => $validated['name'],
            'avatar_path' => $validated['avatar'] ?? null,
            'mobile' => $validated['mobile'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'status' => $validated['status'] ?? UserStatus::Active->value,
        ]);

        return response()->json([
            'ok' => true,
            'user' => $user->fresh(),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $this->validatePayload($request, $user);

        $payload = [
            'name' => $validated['name'],
            'avatar_path' => $validated['avatar'] ?? null,
            'mobile' => $validated['mobile'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'status' => $validated['status'] ?? $user->status->value,
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        $user->fill($payload)->save();

        return response()->json([
            'ok' => true,
            'user' => $user->fresh(),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $references = $this->blockedDeletes->referencesForUser($user);

        if ($references !== []) {
            return response()->json([
                'ok' => false,
                'message' => "Delete blocked for {$user->name}.",
                'references' => $references,
            ], 409);
        }

        $user->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:50'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'role' => ['required', 'string', 'in:caller,operator,command,admin'],
            'status' => ['nullable', 'string', 'in:active,suspended,disabled,pending'],
            'avatar' => ['nullable', 'string', 'max:2048'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
        ]);
    }
}
