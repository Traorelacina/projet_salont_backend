<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Prestation;

class PrestationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $prestations = [
            [
                'libelle' => 'Coupe Homme',
                'prix' => 2000,
                'description' => 'Coupe de cheveux classique pour homme',
                'actif' => true,
                'ordre' => 1,
            ],
            [
                'libelle' => 'Coupe Dame',
                'prix' => 3000,
                'description' => 'Coupe de cheveux pour dame',
                'actif' => true,
                'ordre' => 2,
            ],
            [
                'libelle' => 'Brushing',
                'prix' => 3000,
                'description' => 'Brushing professionnel',
                'actif' => true,
                'ordre' => 3,
            ],
            [
                'libelle' => 'Tresses',
                'prix' => 5000,
                'description' => 'Réalisation de tresses',
                'actif' => true,
                'ordre' => 4,
            ],
            [
                'libelle' => 'Nattes',
                'prix' => 4000,
                'description' => 'Réalisation de nattes',
                'actif' => true,
                'ordre' => 5,
            ],
            [
                'libelle' => 'Barbe',
                'prix' => 1500,
                'description' => 'Taille et entretien de la barbe',
                'actif' => true,
                'ordre' => 6,
            ],
            [
                'libelle' => 'Coloration',
                'prix' => 8000,
                'description' => 'Coloration complète des cheveux',
                'actif' => true,
                'ordre' => 7,
            ],
            [
                'libelle' => 'Soin Capillaire',
                'prix' => 2000,
                'description' => 'Soin profond des cheveux',
                'actif' => true,
                'ordre' => 8,
            ],
            [
                'libelle' => 'Défrisage',
                'prix' => 6000,
                'description' => 'Défrisage des cheveux',
                'actif' => true,
                'ordre' => 9,
            ],
            [
                'libelle' => 'Mèches',
                'prix' => 10000,
                'description' => 'Pose de mèches',
                'actif' => true,
                'ordre' => 10,
            ],
        ];

        foreach ($prestations as $prestation) {
            Prestation::create($prestation);
        }

        $this->command->info('Prestations créées avec succès!');
    }
}
