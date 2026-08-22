#!/bin/bash
set -euo pipefail

# ============================================================
# 本番デプロイスクリプト
# リポジトリ: ~/repo/flortia-attendance
# 公開先:     ~/frontier-dakoku.com/public_html
# ブランチ:   main
# ============================================================

APP_DIR="$HOME/repo/flortia-attendance"
PUBLIC_DIR="$HOME/frontier-dakoku.com/public_html"
PHP_BIN="$HOME/bin/php"
NPM_BIN="$HOME/.nodebrew/current/bin/npm"
BRANCH="main"
LAST_COMMIT_FILE="$APP_DIR/.last_deploy_commit"

echo "=========================================="
echo " [本番] デプロイ開始: $(date '+%Y-%m-%d %H:%M:%S')"
echo "=========================================="

# ----------------------------------------------------------
# 1) ソースコード取得
# ----------------------------------------------------------
echo "[1/7] Gitプル..."
cd "$APP_DIR"
git fetch origin
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

CURR_COMMIT=$(git rev-parse HEAD)
PREV_COMMIT=$(cat "$LAST_COMMIT_FILE" 2>/dev/null || echo "")

# ----------------------------------------------------------
# 2) PHP依存パッケージ（composer.json 変更時のみ）
# ----------------------------------------------------------
COMPOSER_CHANGED=$(git diff --name-only "$PREV_COMMIT" "$CURR_COMMIT" 2>/dev/null | grep -E '^composer\.(json|lock)$' || true)
if [ -z "$PREV_COMMIT" ] || [ -n "$COMPOSER_CHANGED" ]; then
    echo "[2/7] Composer install（変更あり）..."
    $PHP_BIN $(which composer) install --no-dev --optimize-autoloader
else
    echo "[2/7] Composer スキップ（変更なし）"
fi

# ----------------------------------------------------------
# 3) フロントエンドビルド（resources/ / package* / vite* 変更時のみ）
# ----------------------------------------------------------
FRONT_CHANGED=$(git diff --name-only "$PREV_COMMIT" "$CURR_COMMIT" 2>/dev/null | grep -E '^(resources/|package|vite)' || true)
if [ -z "$PREV_COMMIT" ] || [ -n "$FRONT_CHANGED" ]; then
    echo "[3/7] npm build（変更あり）..."
    # tsc / vite は devDependencies にあるため --omit=dev 不可（本番ビルドに全パッケージが必要）
    $NPM_BIN ci
    $NPM_BIN run build
    git restore package-lock.json 2>/dev/null || true
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
# 5) マイグレーション（migrations/ 変更時のみ）
# ----------------------------------------------------------
MIGRATION_CHANGED=$(git diff --name-only "$PREV_COMMIT" "$CURR_COMMIT" 2>/dev/null | grep -E '^database/migrations/' || true)
if [ -z "$PREV_COMMIT" ] || [ -n "$MIGRATION_CHANGED" ]; then
    echo "[4/7] マイグレーション（変更あり）..."
    $PHP_BIN artisan migrate --force
else
    echo "[4/7] マイグレーション スキップ（変更なし）"
fi

# ----------------------------------------------------------
# 6) キャッシュ最適化
# ----------------------------------------------------------
echo "[5/7] キャッシュ最適化..."
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache

# ----------------------------------------------------------
# 7) public/ を公開ディレクトリへ同期
# ----------------------------------------------------------
echo "[6/7] public/ を公開ディレクトリへ同期..."
# --delete は dest にあって src に無いファイルを消す。deploy-dev が public_html/dev に載せるため、
# dev/ だけ削除しない（rsync の protect フィルタ）
rsync -av --delete --filter='P dev/' "$APP_DIR/public/" "$PUBLIC_DIR/"

# ----------------------------------------------------------
# 8) index.php のパス書き換え（Xserver用）
# ----------------------------------------------------------
echo "[7/7] index.php パス書き換え..."
sed -i "s#require __DIR__.'/../vendor/autoload.php';#require __DIR__.'/../../repo/flortia-attendance/vendor/autoload.php';#" "$PUBLIC_DIR/index.php"
sed -i "s#\$app = require_once __DIR__.'/../bootstrap/app.php';#\$app = require_once __DIR__.'/../../repo/flortia-attendance/bootstrap/app.php';#" "$PUBLIC_DIR/index.php"

# ----------------------------------------------------------
# 9) 今回のコミットハッシュを保存
# ----------------------------------------------------------
echo "$CURR_COMMIT" > "$LAST_COMMIT_FILE"

echo ""
echo "=========================================="
echo " [本番] デプロイ完了: $(date '+%Y-%m-%d %H:%M:%S')"
echo " コミット: $CURR_COMMIT"
echo "=========================================="
