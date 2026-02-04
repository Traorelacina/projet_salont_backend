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
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passage_id')->constrained()->onDelete('cascade');
            $table->decimal('montant_total', 10, 2);
            $table->decimal('montant_paye', 10, 2);
            $table->enum('mode_paiement', ['especes', 'mobile_money', 'carte', 'autre'])->default('especes');
            $table->enum('statut', ['en_attente', 'valide', 'annule'])->default('valide');
            $table->text('notes')->nullable();
            $table->timestamp('date_paiement')->useCurrent();
            $table->string('numero_recu')->unique()->nullable();
            $table->string('device_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Index
            $table->index('passage_id');
            $table->index('date_paiement');
            $table->index('numero_recu');
            $table->index('statut');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
