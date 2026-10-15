<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notifications shown in the bell and on /notifications.
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 150);
            $table->text('body');
            $table->string('url', 255)->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at']);
        });

        // Outbound email / SMS with delivery status. dedupe_key makes every automatic message send at most once.
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10); // email, sms
            $table->string('type', 40);
            $table->string('recipient_type', 10); // customer, user
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('destination', 190);
            $table->string('subject', 190)->nullable();
            $table->text('body');
            $table->string('status', 10)->default('queued')->index(); // queued, sent, logged, failed, skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('dedupe_key', 150)->nullable()->unique();
            $table->string('related_type', 40)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'created_at']);
            $table->index(['recipient_type', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('user_notifications');
    }
};
