<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 帳票の一括出力ジョブの進捗・成果物管理。
 * 源泉徴収簿PDF一括作成 / 賃金台帳CSV一括作成 等、年・事業所単位で全従業員分をまとめて出力する
 * 非同期処理を追跡する。
 *
 * 参照: 資料/設計書 26_賃金台帳（一括作成）/ 30_源泉徴収簿（PDFの一括作成）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            // report_type: withholding_book(源泉徴収簿) / wage_ledger(賃金台帳)
            $table->string('report_type');
            // format: pdf_zip(従業員別PDFをZIP) / csv(全従業員を1つのCSV)
            $table->string('format');
            $table->unsignedSmallInteger('year')->comment('対象年');
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            // status: queued/processing/completed/failed
            $table->string('status')->default('queued');
            $table->unsignedInteger('total_count')->default(0)->comment('対象従業員数');
            $table->unsignedInteger('processed_count')->default(0);
            $table->string('file_path')->nullable()->comment('生成ファイルの保存パス（localディスク）');
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
