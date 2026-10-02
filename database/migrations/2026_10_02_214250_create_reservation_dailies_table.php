<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_dailies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('value', 12, 2);
            $table->timestamps();

            $table->unique(['reservation_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_dailies');
    }
};