<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * 管理画面ログイン用アカウントを対話式で作成する。
 *
 * 本番では AdminSeeder（固定パスワードがリポジトリに残る）を使わず、
 * サーバー上でこのコマンドを実行して作成すること。
 */
class AdminCreateCommand extends Command
{
    protected $signature = 'admin:create
        {--name= : 表示名}
        {--email= : ログイン用メールアドレス}
        {--password= : パスワード（省略時は対話入力・推奨）}
        {--role=1 : 1=スーパー管理者（全権限）}
        {--force : 同じメールのアカウントが既にある場合は上書き更新}';

    protected $description = '管理画面ログイン用アカウントを作成する（本番はシーダーではなくこちらを使用）';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('表示名');
        $email = $this->option('email') ?: $this->ask('メールアドレス');
        $role = (int) $this->option('role');

        if ($role !== 1) {
            $this->warn('現状 role=1（スーパー管理者）のみサポートしています。');

            return self::FAILURE;
        }

        $password = $this->option('password');
        if ($password === null || $password === '') {
            $password = $this->secret('パスワード');
            $confirm = $this->secret('パスワード（確認）');
            if ($password !== $confirm) {
                $this->error('パスワードが一致しません。');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $existing = Admin::query()->where('email', $email)->first();
        if ($existing && ! $this->option('force')) {
            $this->error("メールアドレス {$email} は既に登録されています。");
            $this->line('パスワードを更新する場合: php artisan admin:create --email=... --force');

            return self::FAILURE;
        }

        if ($existing && $this->option('force')) {
            $existing->update([
                'name' => $name,
                'password' => $password,
                'role' => $role,
            ]);
            $admin = $existing;
            $this->info('管理ユーザーを更新しました。');
        } else {
            $admin = Admin::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => $role,
            ]);
            $this->info('管理ユーザーを作成しました。');
        }

        $loginPath = env('ADMIN_LOGIN_PATH', 'management-console');
        $baseUrl = rtrim((string) config('app.url'), '/');

        $this->newLine();
        $this->line('  ID:    '.$admin->id);
        $this->line('  名前:  '.$admin->name);
        $this->line('  メール: '.$admin->email);
        $this->line('  ログインURL: '.$baseUrl.'/admin/'.$loginPath);
        $this->newLine();
        $this->comment('パスワードは画面に表示しません。安全な場所に控えてください。');

        return self::SUCCESS;
    }
}
