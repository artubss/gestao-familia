<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\User;
use App\Support\Auth\EmailCode;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Region;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * One-time bootstrap for production (Easypanel / VPS). Set INITIAL_ADMIN_EMAIL and
 * INITIAL_ADMIN_PASSWORD in the app service environment; docker/start.sh runs this after migrations.
 */
class InitialAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = EmailCode::normalize((string) env('INITIAL_ADMIN_EMAIL', ''));
        $password = (string) env('INITIAL_ADMIN_PASSWORD', '');

        if ($email === '' || $password === '') {
            return;
        }

        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            return;
        }

        $user = User::forceCreate([
            'name' => env('INITIAL_ADMIN_NAME', 'Administrador'),
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'locale' => env('INITIAL_ADMIN_LOCALE', 'pt'),
        ]);

        $family = Family::create([
            'name' => env('INITIAL_FAMILY_NAME', 'Nossa família'),
            'color' => Family::ONBOARDING_COLORS[2],
            'emoji' => '🏡',
            'symbol' => 'house',
            'owner_id' => $user->id,
            'theme' => $user->theme ?? 'forest',
        ]);
        $family->forceFill(['hard_paywall' => true])->save();
        $family->members()->attach($user, ['role' => 'admin']);
        app(ModuleRegistry::class)->enableAll($family->id);
        $request = Request::create('/');
        app(Hooks::class)->fire('family.created', $family, $request);
        Region::save($family, ['timezone' => env('INITIAL_FAMILY_TIMEZONE', 'America/Sao_Paulo')]);
    }
}
