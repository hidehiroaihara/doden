# MF 移行用データ（一時的に git 管理）

本番へ `git pull` で渡すため、取込完了までは PDF / CSV をリポジトリに含めます。

## 取込

```bash
php artisan payroll:import-mf-pdf storage/app/mf-migration --dry-run --summary
php artisan payroll:import-mf-pdf storage/app/mf-migration --force

php artisan attendance:import-mf-csv daily_attendance_report_2026_01-2026_08.csv --dry-run
php artisan attendance:import-mf-csv daily_attendance_report_2026_01-2026_08.csv --replace --force
```

本番では `php8.3 artisan ...` を使用。

## 取込完了後（git から削除）

1. データファイルをインデックスから外す（ローカル実ファイルは残しても可）:

   ```bash
   git rm storage/app/mf-migration/*.pdf storage/app/mf-migration/*.csv
   ```

2. `storage/app/.gitignore` の `!mf-migration/**` を `!mf-migration/.gitkeep` に戻す。

3. コミットして push。

以降は `storage/app/mf-migration/` に手動アップロードのみ（git には含めない）。
