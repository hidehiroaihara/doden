<?php

namespace Tests\Feature;

use App\Http\Controllers\DocsController;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 社内資料ビューア（/docs）のアクセス制御。
 */
class DocsViewerTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-docs-password';

    protected function setUp(): void
    {
        parent::setUp();
        config(['docs.password' => self::PASSWORD]);
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => 1,
        ]);
    }

    public function test_password_is_required_before_the_document_is_shown(): void
    {
        $this->get(route('docs.show', 'annual-master-updates'))
            ->assertOk()
            ->assertSee('パスワードを入力してください')
            ->assertDontSee('毎年更新が必要な法定マスタ');
    }

    public function test_correct_password_opens_the_requested_document(): void
    {
        $this->post(route('docs.login'), [
            'password' => self::PASSWORD,
            'intended' => 'annual-master-updates',
        ])->assertRedirect(route('docs.show', 'annual-master-updates'));

        $this->get(route('docs.show', 'annual-master-updates'))
            ->assertOk()
            // Markdown の表が HTML へ変換されている
            ->assertSee('<table>', false)
            ->assertSee('源泉徴収税額表（月額表）');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->from(route('docs.index'))
            ->post(route('docs.login'), ['password' => 'wrong'])
            ->assertRedirect(route('docs.index'))
            ->assertSessionHasErrors('password');

        $this->assertFalse(session()->has(DocsController::SESSION_KEY));
    }

    public function test_logged_in_admin_skips_the_password(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('docs.show', 'annual-master-updates'))
            ->assertOk()
            ->assertSee('源泉徴収税額表（月額表）');
    }

    public function test_only_listed_documents_are_reachable(): void
    {
        $this->post(route('docs.login'), ['password' => self::PASSWORD]);

        $this->get('/docs/payroll-design-guide')->assertOk();
        $this->get('/docs/README')->assertNotFound();
        $this->get('/docs/'.urlencode('../.env'))->assertNotFound();
    }

    public function test_viewer_is_disabled_when_no_password_is_configured(): void
    {
        config(['docs.password' => null]);

        $this->get(route('docs.index'))->assertNotFound();
        $this->get(route('docs.show', 'annual-master-updates'))->assertNotFound();
    }

    public function test_logout_requires_the_password_again(): void
    {
        $this->post(route('docs.login'), ['password' => self::PASSWORD]);
        $this->post(route('docs.logout'))->assertRedirect(route('docs.index'));

        $this->get(route('docs.show', 'annual-master-updates'))
            ->assertSee('パスワードを入力してください');
    }
}
