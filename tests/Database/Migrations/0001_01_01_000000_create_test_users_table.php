<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password')->default('secret');
            $table->string('remember_token')->nullable();
            $table->string('api_token')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('login_count')->default(0);
            $table->timestamps();
        });
    }
};
