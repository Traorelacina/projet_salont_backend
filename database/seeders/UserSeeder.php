<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Créer un administrateur par défaut
        User::create([
            'nom' => 'Admin',
            'prenom' => 'Salon',
            'email' => 'admin@salon.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'actif' => true,
            'telephone' => '+225 01 02 03 04 05',
        ]);

        // Créer un manager par défaut
        User::create([
            'nom' => 'Kouassi',
            'prenom' => 'Jean',
            'email' => 'manager@salon.com',
            'password' => Hash::make('manager123'),
            'role' => 'manager',
            'actif' => true,
            'telephone' => '+225 07 08 09 10 11',
        ]);

        // Créer un caissier par défaut
        User::create([
            'nom' => 'Traore',
            'prenom' => 'Marie',
            'email' => 'caissier@salon.com',
            'password' => Hash::make('caissier123'),
            'role' => 'caissier',
            'actif' => true,
            'telephone' => '+225 05 06 07 08 09',
        ]);

        $this->command->info('✅ Utilisateurs par défaut créés avec succès !');
        $this->command->info('');
        $this->command->info('📧 Admin:    admin@salon.com    / admin123');
        $this->command->info('📧 Manager:  manager@salon.com  / manager123');
        $this->command->info('📧 Caissier: caissier@salon.com / caissier123');
    }
}
