#!/bin/bash
set -euo pipefail

# ============================================================
# 本番デプロイスクリプト（どでん給与システム / XServer VPS）
# アプリ本体: /var/www/doden                 ← 非公開
# 公開:       Nginx docroot = /var/www/doden/public を直接指定
#             （symlink・コピー・公開フォルダ同期は不要）
# PHP:        8.3 固定（php コマンドが 8.5 等になっていても php8.3 を使用）
# ブランチ:   main
# 実行:       deploy ユーザーで  cd /var/www/doden && ./deploy-production.sh
# ============================================================

APP_DIR="/var/www/doden"
PHP_BIN="/usr/bin/php8.3"
BRANCH="main"
QUEUE_SERVICE="doden-queue"
LAST_COMMIT_FILE="$APP_DIR/.last_deploy_commit"

echo "=========================================="
echo " [本番] デプロイ開始: $(date '+%Y-%m-%d %H:%M:%S')"
echo " PHP: $($PHP_BIN -v | head -1)"
echo "=========================================="

# ----------------------------------------------------------
# 1) ソースコード取得
# ----------------------------------------------------------
echo "[1/7] Git プル..."
cd "$APP_DIR"
git fetch origin
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

CURR_COMMIT=$(git rev-parse HEAD)
PREV_COMMIT=$(cat "$LAST_COMMIT_FILE" 2>/dev/null || echo "")

# ----------------------------------------------------------
# 2) PHP 依存パッケージ（composer.json/lock 変更時のみ）
# ----------------------------------------------------------
COMPOSER_CHANGED=$(git diff --name-only "$PREV_COMMIT" "$CURR_COMMIT" 2>/dev/null | grep -E '^composer\.(json|lock)$' || true)
if [ -z "$PREV_COMMIT" ] || [ -n "$COMPOSER_CHANGED" ]; then
    echo "[2/7] Composer install（変更あり）..."
    $PHP_BIN "$(command -v composer)" install --no-dev --optimize-autoloader
else
    echo "[2/7] Composer スキップ（変更なし）"
fi

# ----------------------------------------------------------
# 3) フロントエンドビルド（resources/ / package* / vite* 変更時のみ）
# ----------------------------------------------------------
FRONT_CHANGED=$(git diff --name-only "$PREV_COMMIT" "$CURR_COMMIT" 2>/dev/null | grep -E '^(resources/|package|vite)' || true)
if [ -z "$PREV_COMMIT" ] || [ -n "$FRONT_CHANGED" ]; then
    echo "[3/7] npm build（変更あり）..."
    # tsc / vite は devDependencies のため ci で全部入れる（本番ビルドに必要）
    npm ci
    npm run build
else
    echo "[3/7] npm build スキップ（変更なし）"
fi

# ----------------------------------------------------------
# 4) .env チェック
# ----------------------------------------------------------
if [ ! -f "$APP_DIR/.env" ]; then
    echo "[エラー] .env が存在しません。cp .env.example .env の後に設定してください。"
    exit 1
fi

# ----------------------------------------------------------
# 5) マイグレーション（database/migrations 変更時のみ）
# ----------------------------------------------------------
MIGRATION_CHANGED=$(git diff --name-only "$PREV_COMMIT" "$CURR_COMMIT" 2>/dev/null | grep -E '^database/migrations/' || true)
if [ -z "$PREV_COMMIT" ] || [ -n "$MIGRATION_CHANGED" ]; then
    echo "[4/7] マイグレーション（変更あり）..."
    $PHP_BIN artisan migrate --force
else
    echo "[4/7] マイグレーション スキップ（変更なし）"
fi

# ----------------------------------------------------------
# 6) キャッシュ最適化（本番）
# ----------------------------------------------------------
echo "[5/7] キャッシュ最適化..."
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache

# ----------------------------------------------------------
# 7) 書き込み権限（storage / bootstrap-cache を PHP-FPM 用に）
# ----------------------------------------------------------
echo "[6/7] 権限調整..."
sudo chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# ----------------------------------------------------------
# 8) queue worker 再起動（新コード反映）
# ----------------------------------------------------------
echo "[7/7] queue worker 再起動..."
sudo systemctl restart "$QUEUE_SERVICE"

# ----------------------------------------------------------
# 今回のコミットハッシュを保存（次回の変更検知に使用）
# ----------------------------------------------------------
echo "$CURR_COMMIT" > "$LAST_COMMIT_FILE"

echo ""
echo "=========================================="
echo " [本番] デプロイ完了: $(date '+%Y-%m-%d %H:%M:%S')"
echo " コミット: $CURR_COMMIT"
echo "=========================================="
