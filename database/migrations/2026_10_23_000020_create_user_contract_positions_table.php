<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_contract_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null means outside the contracted team from effective_from.
            $table->string('position')->nullable();
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['user_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_contract_positions');
    }
};
