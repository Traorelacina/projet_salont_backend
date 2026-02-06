<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('passages', function (Blueprint $table) {
            // Supprimer l'ancienne contrainte de clé étrangère si elle existe
            $table->dropForeign(['client_id']);
            
            // Ajouter la nouvelle contrainte avec suppression en cascade
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->onDelete('cascade'); // Supprime automatiquement les passages quand le client est supprimé
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passages', function (Blueprint $table) {
            // Supprimer la contrainte avec cascade
            $table->dropForeign(['client_id']);
            
            // Remettre l'ancienne contrainte sans cascade
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients');
        });
    }
};