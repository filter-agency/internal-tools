<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('breathe_employee_id')->nullable()->unique();
            $table->timestamp('breathe_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['breathe_employee_id']);
            $table->dropColumn(['breathe_employee_id', 'breathe_synced_at']);
        });
    }
};
