<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('us_pincodes', function (Blueprint $table) {
            $table->id();
 
            $table->string('circle_name')->nullable();
            $table->string('region_name')->nullable();
            $table->string('division_name')->nullable();
            $table->string('office_name')->nullable();
            $table->string('pincode', 10);
            $table->string('office_type', 20)->nullable();
            $table->string('delivery', 50)->nullable();
            $table->string('district')->nullable();
            $table->string('state')->nullable();
            $table->string('state_code', 10)->nullable();
 
            $table->index('pincode');
            $table->index('state');
            $table->index('state_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('us_pincodes');
    }
};
