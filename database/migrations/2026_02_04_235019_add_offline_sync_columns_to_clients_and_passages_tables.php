<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration pour ajouter les colonnes de synchronisation hors ligne
 * 
 * Cette migration ajoute les colonnes nécessaires pour gérer
 * la synchronisation des données entre le mode hors ligne et le serveur.
 * 
 * Colonnes ajoutées:
 * - synced_at: timestamp de la dernière synchronisation
 * - device_id: identifiant de l'appareil d'origine (clients uniquement)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Table clients
        Schema::table('clients', function (Blueprint $table) {
            // Colonne pour suivre la dernière synchronisation
            $table->timestamp('synced_at')
                  ->nullable()
                  ->after('updated_at')
                  ->comment('Date de dernière synchronisation avec le serveur');
            
            // Colonne pour identifier l'appareil d'origine
            $table->string('device_id')
                  ->nullable()
                  ->after('synced_at')
                  ->comment('Identifiant de l\'appareil ayant créé le client');
            
            // Index pour améliorer les performances de recherche
            $table->index('synced_at', 'idx_clients_synced_at');
            $table->index('device_id', 'idx_clients_device_id');
        });

        // Table passages
        Schema::table('passages', function (Blueprint $table) {
            // Colonne pour suivre la dernière synchronisation
            $table->timestamp('synced_at')
                  ->nullable()
                  ->after('updated_at')
                  ->comment('Date de dernière synchronisation avec le serveur');
            
            // Index pour améliorer les performances
            $table->index('synced_at', 'idx_passages_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Supprimer les colonnes et index de la table clients
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('idx_clients_synced_at');
            $table->dropIndex('idx_clients_device_id');
            $table->dropColumn(['synced_at', 'device_id']);
        });

        // Supprimer les colonnes et index de la table passages
        Schema::table('passages', function (Blueprint $table) {
            $table->dropIndex('idx_passages_synced_at');
            $table->dropColumn('synced_at');
        });
    }
};