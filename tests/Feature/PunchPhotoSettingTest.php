<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 打刻時の顔写真ON/OFF設定（punch_use_photo）に関するテスト。
 * OFF時は写真なしで打刻でき、ON時は写真が必須になることを検証する。
 */
class PunchPhotoSettingTest extends TestCase
{
    use RefreshDatabase;

    /** 端末認証（punch.access）を満たすための有効な端末を返す。 */
    private function terminal(): Terminal
    {
        return Terminal::create([
            'name' => 'テスト端末',
            'terminal_id' => 'store1-testxx',
            'terminal_key' => Terminal::generateKey(),
            'is_active' => true,
        ]);
    }

    /** GDで生成した有効なJPEG画像を data URL 形式で返す。 */
    private function samplePhoto(): string
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagejpeg($image);
        $data = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/jpeg;base64,'.base64_encode($data);
    }

    public function test_clock_in_succeeds_without_photo_when_setting_off(): void
    {
        Storage::fake('local');
        Setting::setValue('punch_use_photo', '0');

        $terminal = $this->terminal();
        $user = User::factory()->create();

        $response = $this->postJson('/api/attendance/clock-in', [
            'user_id' => $user->id,
            'terminal_id' => $terminal->terminal_id,
            'terminal_key' => $terminal->terminal_key,
        ]);

        $response->assertOk();

        $attendance = Attendance::where('user_id', $user->id)->first();
        $this->assertNotNull($attendance);
        $this->assertNotNull($attendance->clock_in_at);
        $this->assertNull($attendance->clock_in_photo_path);
    }

    public function test_clock_in_requires_photo_when_setting_on(): void
    {
        Storage::fake('local');
        Setting::setValue('punch_use_photo', '1');

        $terminal = $this->terminal();
        $user = User::factory()->create();

        $response = $this->postJson('/api/attendance/clock-in', [
            'user_id' => $user->id,
            'terminal_id' => $terminal->terminal_id,
            'terminal_key' => $terminal->terminal_key,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('photo');
    }

    public function test_clock_in_stores_photo_when_setting_on(): void
    {
        Storage::fake('local');
        Setting::setValue('punch_use_photo', '1');

        $terminal = $this->terminal();
        $user = User::factory()->create();

        $response = $this->postJson('/api/attendance/clock-in', [
            'user_id' => $user->id,
            'terminal_id' => $terminal->terminal_id,
            'terminal_key' => $terminal->terminal_key,
            'photo' => $this->samplePhoto(),
        ]);

        $response->assertOk();

        $attendance = Attendance::where('user_id', $user->id)->first();
        $this->assertNotNull($attendance->clock_in_photo_path);
        Storage::disk('local')->assertExists($attendance->clock_in_photo_path);
    }
}
