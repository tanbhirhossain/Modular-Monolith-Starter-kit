<?php

namespace Database\Seeders;

use Modules\CORE\Database\Seeders\CORESeeder;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;




use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            CORESeeder::class,
            SuperAdminSeeder::class
        ]);
    }
}
