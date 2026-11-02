<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fixed bills (rent, streaming) and read-only Open Finance connections live in the spending namespace.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spending_fixed_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('name');
            $table->bigInteger('amount'); // minor units
            $table->string('currency', 3);
            $table->string('category')->default('other');
            $table->foreignId('payer_id');
            $table->unsignedTinyInteger('day_of_month');
            $table->string('payment_method', 20);
            $table->boolean('active')->default(true);
            $table->string('note')->nullable();
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::create('spending_bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->foreignId('user_id'); // which household member this connector belongs to
            $table->string('label');
            $table->string('provider', 20)->default('pluggy');
            $table->string('item_id')->unique();
            $table->string('connector_name')->nullable();
            $table->string('status', 40)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::table('spending_spends', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('bank_connection_id')->nullable()->constrained('spending_bank_connections')->nullOnDelete();
            $table->foreignId('fixed_expense_id')->nullable()->constrained('spending_fixed_expenses')->nullOnDelete();
            $table->string('external_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('spending_spends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_connection_id');
            $table->dropConstrainedForeignId('fixed_expense_id');
            $table->dropColumn(['payment_method', 'external_id']);
        });
        Schema::dropIfExists('spending_bank_connections');
        Schema::dropIfExists('spending_fixed_expenses');
    }
};
