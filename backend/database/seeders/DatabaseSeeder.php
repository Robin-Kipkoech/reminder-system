<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Department;
use App\Models\ReminderPeriod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Every block is idempotent, so re-running the seeder never duplicates rows.
     */
    public function run(): void
    {
        $this->seedReminderPeriods();
        $this->seedDepartments();
        $this->seedClients();
        $this->seedAdminUser();
    }

    private function seedReminderPeriods(): void
    {
        foreach ([
            ['label' => '7 days before', 'offset_days' => 7],
            ['label' => '3 days before', 'offset_days' => 3],
            ['label' => '2 days before', 'offset_days' => 2],
            ['label' => '1 day before', 'offset_days' => 1],
            ['label' => 'On due day', 'offset_days' => 0],
        ] as $period) {
            ReminderPeriod::updateOrCreate(
                ['offset_days' => $period['offset_days']],
                ['label' => $period['label'], 'is_active' => true],
            );
        }
    }

    private function seedDepartments(): void
    {
        foreach (['Development', 'IT Support', 'Domain & Cloud Hosting'] as $name) {
            Department::updateOrCreate(
                ['name' => $name],
                ['is_active' => true],
            );
        }
    }

    private function seedClients(): void
    {
        foreach ([
            [
                'name' => 'Acme Kenya Ltd',
                'contact_person' => 'Jane Mwangi',
                'email' => 'jane@acme-kenya.test',
                'phone' => '+254700000001',
                'address' => 'Kimathi Street, Nairobi',
                'notes' => 'Sample client created by the seeder.',
            ],
            [
                'name' => 'Blue Ridge Logistics Ltd',
                'contact_person' => 'Peter Otieno',
                'email' => 'peter@blueridge.test',
                'phone' => '+254700000002',
                'address' => 'Enterprise Road, Mombasa',
                'notes' => 'Sample client created by the seeder.',
            ],
        ] as $client) {
            Client::updateOrCreate(
                ['name' => $client['name']],
                [...$client, 'is_active' => true],
            );
        }
    }

    private function seedAdminUser(): void
    {
        // Change this password immediately after the first login.
        DB::table('users')->updateOrInsert(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
