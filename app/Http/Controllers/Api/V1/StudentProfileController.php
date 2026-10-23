<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\School;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentProfileController extends ApiController
{
    public function update(Request $request)
    {
        $user = $request->user();
        $this->authorize('accessStudent', Student::class);
        $student = $user->student;
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'region_id' => ['sometimes', 'required', 'integer', 'exists:regions,id'],
            'school_id' => ['sometimes', 'required', 'integer', 'exists:schools,id'],
            'grade' => ['sometimes', 'required', 'string', 'max:100'],
        ]);
        $schoolId = $data['school_id'] ?? $student->school_id;
        $regionId = $data['region_id'] ?? $user->region_id;
        if ($schoolId && ! School::whereKey($schoolId)->where('region_id', $regionId)->exists()) {
            throw ValidationException::withMessages(['school_id' => ['School must belong to the selected region.']]);
        }
        DB::transaction(function () use ($data, $user, $student) {
            $user->update(collect($data)->only(['name', 'phone', 'email', 'region_id'])->all());
            $student->update(collect($data)->only(['school_id', 'grade'])->all());
        });

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
