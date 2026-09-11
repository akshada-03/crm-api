<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->text('body');
            $table->dateTime('occurred_at');
            $table->timestamps();

            // Lead timeline ordered by occurred_at (show) and the won/lost exists() check; also backs the lead_id FK.
            $table->index(['lead_id', 'occurred_at']);
            // Rep performance report: activity count per user; also backs the user_id FK.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
