<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 給与明細ZIP出力ジョブの進捗・成果物管理。
 * 期間(from〜to)の全明細を「従業員/月/PDF」階層でZIP化する非同期処理を追跡する。
 *
 * 参照: 資料/設計書 19_給与明細（ZIP一括出力）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period_from', 7)->comment('対象開始 Y-m');
            $table->string('period_to', 7)->comment('対象終了 Y-m');
            // status: queued(待機)/processing(処理中)/completed(完了)/failed(失敗)
            $table->string('status')->default('queued');
            $table->unsignedInteger('total_count')->default(0)->comment('対象明細数');
            $table->unsignedInteger('processed_count')->default(0)->comment('処理済み明細数');
            $table->string('file_path')->nullable()->comment('生成ZIPの保存パス（localディスク）');
            $table->string('file_name')->nullable()->comment('ダウンロード時のファイル名');
            $table->unsignedBigInteger('file_size')->nullable()->comment('ZIPサイズ（byte）');
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->comment('依頼した管理者')->constrained('admins')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_exports');
    }
};
