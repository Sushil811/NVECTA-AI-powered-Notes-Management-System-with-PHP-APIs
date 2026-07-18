<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id(); // BIGINT AUTO_INCREMENT PRIMARY KEY
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Reference to user owning the note
            $table->string('title');
            $table->longText('content');
            $table->text('summary')->nullable();
            $table->string('category')->default('work'); // Added note category: 'work', 'personal', 'ideas'
            $table->string('vector_id')->nullable(); // Reference to vector in local database
            $table->timestamps();

            // Setup required indexes
            $table->index('title');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
