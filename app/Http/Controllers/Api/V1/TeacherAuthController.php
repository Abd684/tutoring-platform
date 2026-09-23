<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class TeacherAuthController extends Controller
{
    public function registerTeacher(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'required|string|max:20|unique:users,phone',
            'region_id' => 'required|exists:regions,id',
            'specialization' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:1000',
        ]);

        try {
            DB::transaction(function () use ($validatedData) {

                $user = User::create([
                    'name' => $validatedData['name'],
                    'phone' => $validatedData['phone'],
                    'email' => $validatedData['email'],
                    'password_hash' => Hash::make($validatedData['password']),
                    'role' => 'teacher',
                    'region_id' => $validatedData['region_id'],
                    'status' => 'active',
                ]);

                Teacher::create([
                    'user_id' => $user->id,
                    'bio' => $validatedData['bio'] ?? null,
                    'specialization' => $validatedData['specialization'] ?? null,
                    'status' => 'pending_review',
                ]);
            });

            return response()->json([
                'message' => 'Teacher registered successfully',
            ], 201);

        } catch (\Throwable $e) {

            Log::error('Teacher registration failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Registration failed',
            ], 500);
        }
    }

    public function activateTeacher($id)
    {
        $teacher = Teacher::findOrFail($id);

        $teacher->update([
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Teacher activated successfully',
        ]);
    }
}
