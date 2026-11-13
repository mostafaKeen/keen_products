<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
        ]);

        $isLocalOrTesting = app()->environment('local', 'testing');
        $adminEmail = env('SUPER_ADMIN_EMAIL', $isLocalOrTesting ? 'admin@example.com' : '');
        $adminPassword = (string) env('SUPER_ADMIN_PASSWORD', $isLocalOrTesting ? 'password' : '');

        if ($adminEmail === '' || (! $isLocalOrTesting && ($adminPassword === '' || $adminPassword === 'password'))) {
            $this->command?->warn('Skipping Super Admin seeding outside local/testing: set non-default SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD.');

            return;
        }

        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
                'password' => Hash::make($adminPassword),
                'email_verified_at' => now(),
            ]
        );
        $admin->is_super_admin = true;
        $admin->save();

        if ($isLocalOrTesting) {
            User::firstOrCreate(
                ['email' => 'test@example.com'],
                [
                    'name' => 'Test User',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
