<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentProfileController extends ApiController
{
    public function update(Request $request)
    {
        $user = $request->user();
        $this->authorize('accessStudent', Student::class);
        $student = $user->student;
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'profile_image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'phone' => ['prohibited'], 'email' => ['prohibited'], 'password' => ['prohibited'],
            'region_id' => ['prohibited'], 'school_id' => ['prohibited'], 'grade' => ['prohibited'],
        ]);
        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }
        if ($request->hasFile('profile_image')) {
            $avatarRequest = Request::create('/profile/avatar', 'POST', [], [], ['avatar' => $request->file('profile_image')]);
            $avatarRequest->setUserResolver(fn () => $user);
            app(ProfileAvatarController::class)->store($avatarRequest);
            $user->refresh();
        }

        return app(StudentAuthController::class)->studentMe($request);
    }

    public function storeAvatar(Request $request)
    {
        $this->authorize('accessStudent', Student::class);
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $user = $request->user();
        $path = $request->file('avatar')->store('avatars/'.$user->id, 'public');
        abort_unless($path, 500, 'Avatar could not be stored.');
        $old = $user->avatar_path;
        try {
            $user->forceFill(['avatar_path' => $path])->save();
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $this->success(['avatar_url' => $user->avatar_url]);
    }

    public function deleteAvatar(Request $request)
    {
        $this->authorize('accessStudent', Student::class);
        $user = $request->user();
        $old = $user->avatar_path;
        $user->forceFill(['avatar_path' => null])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $this->success(['avatar_url' => null]);
    }
}
