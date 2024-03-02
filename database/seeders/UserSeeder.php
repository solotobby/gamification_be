<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $users = [
            ['id' => '1', 'name' => 'Admin User', 'email' => 'admin@gmail.com', 'password' => bcrypt('solomon001'),  'role' => 'admin'],
            ['id' => '2', 'name' => 'Oluwatobi', 'email' => 'solotob@gmail.com', 'password' => bcrypt('solomon001'),  'role' => 'admin'],
            ['id' => '3', 'name' => 'Samuel', 'email' => 'samuel@gmail.com', 'password' => bcrypt('solomon001'),  'role' => 'admin']
        ];

        foreach($users as $user)
        {
             User::updateOrCreate($user);
        }
    }
}
