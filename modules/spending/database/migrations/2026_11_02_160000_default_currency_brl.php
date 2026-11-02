<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The app default is the real. Existing families that still have the old euro fallback (or none)
// pick BRL; a currency someone chose on purpose is left alone. New lists without a currency follow BRL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_events', function (Blueprint $table) {
            $table->string('currency', 3)->default('BRL')->change();
        });

        DB::table('spending_settings')
            ->where(fn ($q) => $q->whereNull('default_currency')->orWhere('default_currency', 'EUR'))
            ->update(['default_currency' => 'BRL']);
    }

    public function down(): void
    {
        Schema::table('spending_events', function (Blueprint $table) {
            $table->string('currency', 3)->default('EUR')->change();
        });
    }
};
