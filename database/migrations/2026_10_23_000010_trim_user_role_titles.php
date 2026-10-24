<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tidy role titles saved before User normalised them on write (trailing
     * spaces, doubled spaces, blank strings). Done in PHP so it runs the same
     * on SQLite and MySQL.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('role_title')
            ->eachById(function (object $row): void {
                $tidy = User::normaliseRoleTitle($row->role_title);

                if ($tidy !== $row->role_title) {
                    DB::table('users')->where('id', $row->id)->update(['role_title' => $tidy]);
                }
            });
    }

    public function down(): void
    {
        // Data tidy-up only; the original spacing is not worth restoring.
    }
};
