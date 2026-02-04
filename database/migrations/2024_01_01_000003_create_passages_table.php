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
        Schema::create('passages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->integer('numero_passage')->comment('Numéro du passage pour ce client');
            $table->boolean('est_gratuit')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('date_passage')->useCurrent();
            $table->string('device_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Index
            $table->index('client_id');
            $table->index('date_passage');
            $table->index(['client_id', 'numero_passage']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passages');
    }
};
