<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class SendChatworkNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        private User $user,
        private string $type,
        private Attendance $attendance,
        private string $photoPath,
    ) {}

    public function handle(): void
    {
        $token = $this->normalizeApiToken((string) config('services.chatwork.api_token'));
        $roomId = $this->normalizeRoomId((string) ($this->user->chatwork_room_id ?? ''));

        if ($token === '' || $roomId === '') {
            Log::warning('Chatwork notification skipped: missing token or room_id', [
                'user_id' => $this->user->id,
                'has_token' => $token !== '',
                'has_room_id' => $roomId !== '',
            ]);

            return;
        }

        Log::info('Chatwork notification job started', [
            'user_id' => $this->user->id,
            'room_id' => $roomId,
            'type' => $this->type,
        ]);

        $timestamp = $this->type === '出勤'
            ? $this->attendance->clock_in_at->format('Y-m-d H:i')
            : $this->attendance->clock_out_at->format('Y-m-d H:i');

        $ip = $this->type === '出勤'
            ? $this->attendance->clock_in_ip
            : $this->attendance->clock_out_ip;

        $adminAttendancesUrl = URL::route('admin.users.attendances', ['user' => $this->user->id], true);

        $message = "[info][title]打刻通知 {$timestamp}[/title]"
            ."ユーザー：{$this->user->name}\n"
            ."種別：{$this->type}\n"
            ."日時：{$timestamp}\n"
            ."端末IP：{$ip}\n"
            ."管理画面（打刻一覧）：[url]{$adminAttendancesUrl}[/url][/info]";

        // テキストメッセージを先に送信（必ずチャット画面に表示）
        if (! $this->sendMessage($token, $roomId, $message)) {
            throw new \RuntimeException(
                'Chatwork: text message could not be sent (see previous log lines for HTTP status/body).'
            );
        }

        // 画像を別途アップロード（チャット画面にインライン表示）
        $this->uploadPhoto($token, $roomId);
    }

    /**
     * .env の引用符・改行・前後空白を除いた API トークン。
     */
    private function normalizeApiToken(string $raw): string
    {
        $t = trim($raw, " \t\n\r\0\x0B\"'");

        return $t;
    }

    /**
     * 数値のみ、または Chatwork URL からルームIDを抽出。
     */
    private function normalizeRoomId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (ctype_digit($raw)) {
            return $raw;
        }
        if (preg_match('/[#&?]rid(\d+)/i', $raw, $m)) {
            return $m[1];
        }
        if (preg_match('/rooms\/(\d+)/i', $raw, $m)) {
            return $m[1];
        }

        return $raw;
    }

    /**
     * 画像のみアップロード（テキストメッセージとは別にチャット画面へインライン表示）。
     * 失敗してもテキストは送信済みなので例外は投げない。
     */
    private function uploadPhoto(string $token, string $roomId): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($this->photoPath)) {
            Log::warning('Photo file not found for Chatwork upload', [
                'relative_path' => $this->photoPath,
                'resolved_path' => $disk->path($this->photoPath),
                'user_id' => $this->user->id,
            ]);

            return;
        }

        $filePath = $disk->path($this->photoPath);
        $filename = basename($this->photoPath) ?: 'punch.jpg';
        $caption = '打刻写真：'.$this->user->name.'（'.$this->type.'）';

        // CURLFile を使い multipart/form-data で確実にアップロード（Python/Node.js 実装と同等）
        $cfile = new \CURLFile($filePath, 'image/jpeg', $filename);

        $ch = curl_init();
        curl_setopt_array($ch, [
            \CURLOPT_URL => "https://api.chatwork.com/v2/rooms/{$roomId}/files",
            \CURLOPT_POST => true,
            \CURLOPT_HTTPHEADER => ["X-ChatWorkToken: {$token}"],
            \CURLOPT_POSTFIELDS => ['file' => $cfile, 'message' => $caption],
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 120,
        ]);

        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError !== '') {
            Log::warning('Chatwork photo upload curl error', [
                'error' => $curlError,
                'user_id' => $this->user->id,
            ]);

            return;
        }

        if ($status === 429) {
            $this->release(60);

            return;
        }

        if ($status < 200 || $status >= 300) {
            Log::warning('Chatwork photo upload failed', [
                'status' => $status,
                'body' => $body,
                'user_id' => $this->user->id,
            ]);

            return;
        }

        $json = json_decode($body, true);
        if (is_array($json) && isset($json['errors'])) {
            Log::warning('Chatwork photo upload returned errors in JSON', [
                'errors' => $json['errors'],
                'user_id' => $this->user->id,
            ]);

            return;
        }

        Log::info('Chatwork photo upload succeeded', [
            'user_id' => $this->user->id,
            'room_id' => $roomId,
            'file_id' => $json['file_id'] ?? null,
        ]);
    }

    private function sendMessage(string $token, string $roomId, string $message): bool
    {
        $response = Http::withHeaders([
            'X-ChatWorkToken' => $token,
        ])->asForm()->post(
            "https://api.chatwork.com/v2/rooms/{$roomId}/messages",
            ['body' => $message]
        );

        if ($response->status() === 429) {
            $retryAfter = (int) $response->header('Retry-After', 60);
            $this->release($retryAfter);

            return true;
        }

        if ($response->failed()) {
            Log::error('Chatwork notification failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'user_id' => $this->user->id,
                'room_id' => $roomId,
            ]);

            return false;
        }

        $json = $response->json();
        if (is_array($json) && isset($json['errors'])) {
            Log::error('Chatwork message API returned errors in JSON', [
                'errors' => $json['errors'],
                'user_id' => $this->user->id,
                'room_id' => $roomId,
            ]);

            return false;
        }

        Log::info('Chatwork message sent', [
            'user_id' => $this->user->id,
            'room_id' => $roomId,
            'message_id' => $json['message_id'] ?? null,
        ]);

        return true;
    }
}
