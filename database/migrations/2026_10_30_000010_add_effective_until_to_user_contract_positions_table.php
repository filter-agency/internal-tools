<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_contract_positions', function (Blueprint $table) {
            // Last day the position applies; null means until the next change.
            $table->date('effective_until')->nullable()->after('effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('user_contract_positions', function (Blueprint $table) {
            $table->dropColumn('effective_until');
        });
    }
};
