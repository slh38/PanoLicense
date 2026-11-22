<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Admin hesabını sunucuda oluşturur veya parolasını sıfırlar. Parola etkileşimli ve gizli sorulur;
 * komut satırına, loga veya repoya yazılmaz.
 *
 *   sudo -u panolicense php artisan panolicense:create-admin
 */
class CreateAdmin extends Command
{
    protected $signature = 'panolicense:create-admin';

    protected $description = 'Admin kullanıcısı oluşturur (veya mevcut Admin\'in parolasını sıfırlar)';

    public function handle(): int
    {
        $username = mb_strtolower(trim((string) $this->ask('Kullanıcı adı (küçük harf, rakam, . _ -)')));
        $existing = User::where('username', $username)->first();

        if ($existing && ! $existing->isAdmin()) {
            $this->error('Bu kullanıcı adı bir User hesabına ait; Admin yapılamaz.');

            return self::FAILURE;
        }

        $name = $existing?->name ?? (string) $this->ask('Ad Soyad');
        $password = (string) $this->secret('Parola (en az 12 karakter, harf ve rakam)');
        $confirm = (string) $this->secret('Parola (tekrar)');

        $validator = Validator::make(
            ['username' => $username, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirm],
            [
                'username' => ['required', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/'],
                'name' => ['required', 'min:2', 'max:100'],
                'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            ],
            ['username.regex' => 'Kullanıcı adı yalnız küçük harf, rakam, nokta (.), alt çizgi (_) ve tire (-) içerebilir; e-posta ve @ kullanılamaz.'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if ($existing) {
            $existing->update(['password' => $password, 'is_active' => true]);
            Audit::log('user.admin_password_reset', $existing, ['via' => 'artisan'], userId: null);
            $this->info("Admin parolası güncellendi: {$username}");
        } else {
            $user = User::create([
                'name' => $name,
                'username' => $username,
                'password' => $password,
                'role' => UserRole::Admin,
                'is_active' => true,
            ]);
            Audit::log('user.admin_create', $user, ['via' => 'artisan'], userId: null);
            $this->info("Admin oluşturuldu: {$username}");
        }

        return self::SUCCESS;
    }
}
