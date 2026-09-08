<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            // Номер сторінки PDF (1-based), з якої розпізнано фрагмент —
            // потрібен, щоб посилання на джерело вело на конкретну сторінку.
            $table->unsignedInteger('page_number')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropColumn('page_number');
        });
    }
};
