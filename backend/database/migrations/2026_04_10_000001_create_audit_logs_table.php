<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('action');
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('admin_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('target_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
