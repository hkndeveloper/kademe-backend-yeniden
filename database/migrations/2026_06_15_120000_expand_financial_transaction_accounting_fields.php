<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE financial_transactions MODIFY category VARCHAR(80) NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE financial_transactions ALTER COLUMN category TYPE VARCHAR(80)');
        }

        Schema::table('financial_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('financial_transactions', 'spending_unit')) {
                $table->string('spending_unit', 150)->nullable()->after('project_id');
            }
            if (! Schema::hasColumn('financial_transactions', 'invoice_no')) {
                $table->string('invoice_no', 100)->nullable()->after('invoice_path');
            }
            if (! Schema::hasColumn('financial_transactions', 'payment_date')) {
                $table->date('payment_date')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('financial_transactions', 'payment_method')) {
                $table->string('payment_method', 80)->nullable()->after('payment_date');
            }
            if (! Schema::hasColumn('financial_transactions', 'accounting_code')) {
                $table->string('accounting_code', 80)->nullable()->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            foreach (['spending_unit', 'invoice_no', 'payment_date', 'payment_method', 'accounting_code'] as $column) {
                if (Schema::hasColumn('financial_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
