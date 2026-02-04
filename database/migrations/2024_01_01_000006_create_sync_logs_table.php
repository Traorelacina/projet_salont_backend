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
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('device_id');
            $table->string('entity_type')->comment('Type: client, prestation, passage, paiement');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action')->comment('Action: create, update, delete');
            $table->json('data_before')->nullable();
            $table->json('data_after')->nullable();
            $table->enum('statut', ['en_attente', 'succes', 'echec', 'conflit'])->default('en_attente');
            $table->text('message_erreur')->nullable();
            $table->timestamp('date_sync')->useCurrent();
            $table->timestamps();
            
            // Index
            $table->index('device_id');
            $table->index('entity_type');
            $table->index('statut');
            $table->index('date_sync');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
