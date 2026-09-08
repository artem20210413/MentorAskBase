<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_logs', function (Blueprint $table) {
            // Відсоток релевантності найкращого (найближчого) фрагмента,
            // використаного для відповіді: 100% = ідентичний вектор,
            // 0% = повністю нерелевантний. null, якщо джерел не знайдено.
            $table->unsignedTinyInteger('best_match_score')->nullable()->after('source_document_ids');
        });
    }

    public function down(): void
    {
        Schema::table('query_logs', function (Blueprint $table) {
            $table->dropColumn('best_match_score');
        });
    }
};
