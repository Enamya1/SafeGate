<?php

namespace Database\Seeders;

use App\Models\Dormitory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = base_path('../ui_test/test_data/users.json');

        if (!File::exists($jsonPath)) {
            $jsonPath = base_path('ui_test/test_data/users.json');
        }

        if (!File::exists($jsonPath)) {
            $this->command->error("JSON file not found at: $jsonPath");
            return;
        }

        $users = json_decode(File::get($jsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error('Failed to parse JSON: ' . json_last_error_msg());
            return;
        }

        $count = 0;
        foreach ($users as $user) {
            $dormitory = Dormitory::where('domain', $user['dormitory_domain'])->first();

            if (!$dormitory) {
                $this->command->warn("Dormitory not found: {$user['dormitory_domain']}");
                continue;
            }

            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'full_name' => $user['full_name'],
                    'username' => $user['username'],
                    'phone_number' => $user['phone_number'] ?? null,
                    'profile_picture' => $user['profile_picture'] ?? null,
                    'student_id' => $user['student_id'] ?? null,
                    'bio' => $user['bio'] ?? null,
                    'gender' => $user['gender'] ?? null,
                    'password' => Hash::make('password123'),
                    'dormitory_id' => $dormitory->id,
                    'role' => $user['role'] ?? 'user',
                    'status' => $user['status'] ?? 'active',
                    'account_completed' => true,
                    'email_verified_at' => $user['email_verified'] ? now() : null,
                ]
            );
            $count++;
        }

        $this->command->info("Successfully seeded $count users.");
    }
}