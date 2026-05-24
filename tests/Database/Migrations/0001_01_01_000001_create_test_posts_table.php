<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_user_id')->constrained('test_users');
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });
    }
};
