<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PostmanTestSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create([
            'name' => 'Postman Teacher',
            'phone' => '0999999999',
            'email' => 'postman.teacher@example.com',
            'password_hash' => Hash::make('Password123!'),
            'role' => 'teacher',
            'status' => 'active',
        ]);

        Teacher::create([
            'user_id' => $user->id,
            'specialization' => 'API Testing',
            'status' => 'active',
        ]);

        SubscriptionPlan::create([
            'name' => 'Postman Monthly Plan',
            'price' => 25,
            'billing_cycle' => 'monthly',
            'commission_rate' => 5,
            'max_students' => 20,
            'max_storage' => 1024,
            'max_ai_usage' => 100,
            'max_group_size' => 10,
            'status' => 'active',
        ]);
    }
}
