<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Informations du Salon
    |--------------------------------------------------------------------------
    |
    | Ces informations sont utilisées pour les reçus et documents officiels.
    |
    */

    'nom' => env('SALON_NAME', 'Salon de Coiffure'),
    'adresse' => env('SALON_ADDRESS', 'Abidjan, Côte d\'Ivoire'),
    'telephone' => env('SALON_PHONE', '+225 00 00 00 00'),
    'email' => env('SALON_EMAIL', 'contact@salon.ci'),

    /*
    |--------------------------------------------------------------------------
    | Règles de Fidélité
    |--------------------------------------------------------------------------
    |
    | Configuration du système de fidélité du salon.
    |
    */

    'fidelite' => [
        'passages_gratuit' => env('FIDELITE_PASSAGES_GRATUIT', 10),
        'actif' => env('FIDELITE_ACTIF', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Synchronisation
    |--------------------------------------------------------------------------
    |
    | Paramètres pour la synchronisation offline-first.
    |
    */

    'sync' => [
        'batch_size' => env('SYNC_BATCH_SIZE', 100),
        'retry_attempts' => env('SYNC_RETRY_ATTEMPTS', 3),
        'timeout' => env('SYNC_TIMEOUT', 30), // secondes
    ],

    /*
    |--------------------------------------------------------------------------
    | Reçus
    |--------------------------------------------------------------------------
    |
    | Configuration pour la génération des reçus.
    |
    */

    'recu' => [
        'prefix' => 'REC',
        'format_numero' => 'YYYYMMDD-XXXXXX', // Année Mois Jour - Random
        'inclure_logo' => true,
    ],

];
