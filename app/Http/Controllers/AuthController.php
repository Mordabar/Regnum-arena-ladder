<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function redirectToDiscord()
    {
        return Socialite::driver('discord')->redirect();
    }

    public function handleDiscordCallback()
    {
        try {
            $discordUser = Socialite::driver('discord')->user();
            $adminIds = config('services.discord.admin_ids', []);
            
            $existingUser = User::where('discord_id', $discordUser->id)->first();

            $user = User::updateOrCreate([
                'discord_id' => $discordUser->id,
            ], [
                'discord_username' => $discordUser->nickname ?? $discordUser->name,
                'discord_discriminator' => $discordUser->user['discriminator'] ?? null,
                'discord_avatar' => $discordUser->avatar,
                'name' => $discordUser->name,
                'email' => $discordUser->email,
                'is_admin' => ($existingUser?->is_admin ?? false)
                    || in_array((string) $discordUser->id, $adminIds, true),
            ]);

            Auth::login($user);

            return redirect()->route('lobby');
            
        } catch (\Exception $e) {
            return redirect()->route('home')->with('error', 'Error al autenticar con Discord');
        }
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
