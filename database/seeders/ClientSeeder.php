<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Client;

class ClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $clients = [
            [
                'nom' => 'Konan',
                'prenom' => 'Yao',
                'telephone' => '0700000001',
                'nombre_passages' => 0,
            ],
            [
                'nom' => 'Traoré',
                'prenom' => 'Aïcha',
                'telephone' => '0500000002',
                'nombre_passages' => 4,
            ],
            [
                'nom' => 'Diabaté',
                'prenom' => 'Moussa',
                'telephone' => '0100000003',
                'nombre_passages' => 9,
            ],
            [
                'nom' => 'Kouassi',
                'prenom' => 'Marie',
                'telephone' => '0700000004',
                'nombre_passages' => 12,
            ],
            [
                'nom' => 'N\'Guessan',
                'prenom' => 'Jean',
                'telephone' => '0500000005',
                'nombre_passages' => 7,
            ],
        ];

        foreach ($clients as $client) {
            Client::create($client);
        }

        $this->command->info('Clients de test créés avec succès!');
    }
}
