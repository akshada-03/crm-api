<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30);
            $table->string('company')->nullable();
            $table->string('source', 20);
            $table->string('status', 20)->default('new');
            $table->decimal('expected_value', 12, 2)->default(0);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Rep performance report (count/sum per rep and status, index-only) and rep scoping; also backs the FK.
            $table->index(['assigned_to', 'status', 'expected_value']);
            // Lead list filtered by status.
            $table->index('status');
            // Lead list filtered by source.
            $table->index('source');
            // Lead list default sort (created_at).
            $table->index('created_at');
            // Lead list sorted by expected_value.
            $table->index('expected_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
