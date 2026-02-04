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
        // Ajouter la colonne coiffeur_id dans la table pivot passage_prestation
        Schema::table('passage_prestation', function (Blueprint $table) {
            $table->unsignedBigInteger('coiffeur_id')->nullable()->after('quantite');
            $table->foreign('coiffeur_id')->references('id')->on('users')->onDelete('set null');
            $table->index('coiffeur_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passage_prestation', function (Blueprint $table) {
            $table->dropForeign(['coiffeur_id']);
            $table->dropIndex(['coiffeur_id']);
            $table->dropColumn('coiffeur_id');
        });
    }
};