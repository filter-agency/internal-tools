<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('month_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            // First day of the locked calendar month.
            $table->date('month');
            $table->date('issued_on');
            $table->foreignId('locked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'month']);
        });

        Schema::create('month_lock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('month_lock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->decimal('hours', 10, 2);

            $table->unique(['month_lock_id', 'project_id', 'task_id']);
        });

        Schema::create('month_lock_overrides', function (Blueprint $table) {
            $table->id();
            // Restrict, not cascade: deleting a lock must not erase who overrode it and why.
            $table->foreignId('month_lock_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['month_lock_id', 'user_id']);
        });

        Schema::create('month_lock_override_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('month_lock_override_id')->constrained()->restrictOnDelete();
            // Deliberately no foreign key: the log must survive the entry being deleted.
            $table->unsignedBigInteger('time_entry_id')->index();
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('month_lock_override_changes');
        Schema::dropIfExists('month_lock_overrides');
        Schema::dropIfExists('month_lock_snapshots');
        Schema::dropIfExists('month_locks');
    }
};
