<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurkishValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_messages_and_field_names_are_turkish(): void
    {
        config(['app.locale' => 'tr']);
        app()->setLocale('tr');
        $admin = User::factory()->admin()->create();

        $errors = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => '',
            'username' => 'Büyük Harf@',
            'password' => 'kisa',
        ])->assertUnprocessable()->json('errors');

        $this->assertSame('Ad zorunludur.', $errors['name'][0]);
        $this->assertStringStartsWith('Kullanıcı adı', $errors['username'][0]);
        $this->assertSame('Parola en az 10 karakter olmalıdır.', $errors['password'][0]);
    }

    public function test_create_admin_command_reports_username_error_in_turkish(): void
    {
        app()->setLocale('tr');

        $this->artisan('panolicense:create-admin')
            ->expectsQuestion('Kullanıcı adı (küçük harf, rakam, . _ -)', 'salih@ornek.com')
            ->expectsQuestion('Ad Soyad', 'Salih')
            ->expectsQuestion('Parola (en az 12 karakter, harf ve rakam)', 'Cok-Gizli-Parola-42')
            ->expectsQuestion('Parola (tekrar)', 'Cok-Gizli-Parola-42')
            ->expectsOutputToContain('Kullanıcı adı yalnız küçük harf')
            ->assertFailed();
    }
}
