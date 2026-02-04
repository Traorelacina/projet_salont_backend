<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modifier l'enum de la colonne role pour ajouter 'coiffeur'
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'manager', 'caissier', 'coiffeur') NOT NULL DEFAULT 'caissier'");
        
        // Optionnel : Ajouter une colonne pour la spécialité du coiffeur
        Schema::table('users', function (Blueprint $table) {
            $table->string('specialite')->nullable()->after('role');
            $table->decimal('commission', 5, 2)->nullable()->after('specialite')->comment('Commission en pourcentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Supprimer les colonnes ajoutées
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['specialite', 'commission']);
        });
        
        // Retirer 'coiffeur' de l'enum (attention : cela peut causer des problèmes si des utilisateurs ont ce rôle)
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'manager', 'caissier') NOT NULL DEFAULT 'caissier'");
    }
};