<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProfileAvatarController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->status === 'active', 403, 'Active account required.');
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096']]);
        $path = $request->file('avatar')->store('avatars/'.$request->user()->id, 'public');
        abort_unless($path, 500, 'Unable to store avatar.');
        try {
            [$user, $oldPath] = DB::transaction(function () use ($request, $path): array {
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                $oldPath = $user->avatar_path;
                $user->avatar_path = $path;
                $user->save();

                return [$user, $oldPath];
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete($path);
            throw $error;
        }
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $request->user()->refresh();

        return $this->success(['avatar_url' => $user->avatar_url], 'Avatar updated successfully.');
    }

    public function destroy(Request $request): JsonResponse
    {
        abort_unless($request->user()->status === 'active', 403, 'Active account required.');
        $oldPath = DB::transaction(function () use ($request): ?string {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $oldPath = $user->avatar_path;
            $user->avatar_path = null;
            $user->save();

            return $oldPath;
        });
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $request->user()->refresh();

        return $this->success(['avatar_url' => null], 'Avatar deleted successfully.');
    }

    public function show(User $user, string $filename): StreamedResponse
    {
        $path = 'avatars/'.$user->id.'/'.$filename;
        abort_unless($user->avatar_path === $path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
