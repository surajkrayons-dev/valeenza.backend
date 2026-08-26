<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_taxes', function (Blueprint $table) {
            $table->id();

            $table->string('state_code', 2)->unique();
            $table->string('state_name');

            $table->decimal('state_rate', 5, 2)->default(0.00);
            $table->decimal('local_rate', 5, 2)->default(0.00);
            $table->decimal('combined_rate', 5, 2)->default(0.00);

            $table->boolean('status')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_taxes');
    }
};