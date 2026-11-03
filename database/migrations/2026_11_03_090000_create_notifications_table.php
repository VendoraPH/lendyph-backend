<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's database notifications: the staff in-app notification bell
 * (decision N, Arvin, 2026-11-02). `User` is already Notifiable; the database
 * channel writes one row per recipient. Staff only: nothing here reaches a
 * borrower, and there is no mail or SMS channel.
 *
 * `type` holds the event (`loan_released`, `approval_needed`, ...), not the
 * class name: App\Notifications\StaffNotification sets it with databaseType().
 *
 * The (notifiable, read_at) index serves the bell's unread count, which every
 * signed-in browser polls once a minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
