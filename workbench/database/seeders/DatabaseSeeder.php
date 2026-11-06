<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\Database\Factories\UserFactory;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        UserFactory::new()->create(['name' => 'Admin', 'email' => 'admin@demo.test']);
        UserFactory::new()->create(['name' => 'Deployer', 'email' => 'deployer@demo.test']);
        UserFactory::new()->inactive()->create(['name' => 'Deactivated', 'email' => 'deactivated@demo.test']);
    }
}
