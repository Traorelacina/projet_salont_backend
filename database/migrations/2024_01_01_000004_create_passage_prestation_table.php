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
        Schema::create('passage_prestation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passage_id')->constrained()->onDelete('cascade');
            $table->foreignId('prestation_id')->constrained()->onDelete('cascade');
            $table->decimal('prix_applique', 10, 2)->comment('Prix au moment de la prestation');
            $table->integer('quantite')->default(1);
            $table->timestamps();
            
            // Index
            $table->index('passage_id');
            $table->index('prestation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passage_prestation');
    }
};
