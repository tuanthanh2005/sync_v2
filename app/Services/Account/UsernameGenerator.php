<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Str;

class UsernameGenerator
{
    /**
     * Generate a unique URL-safe lowercase username from a display name or email.
     */
    public function generate(string $seed): string
    {
        $base = Str::of(Str::ascii($seed))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(28, '')
            ->toString();

        if (strlen($base) < 3) {
            $base = 'member';
        }

        $candidate = $base;
        $suffix = 1;

        while (User::withTrashed()->where('username', $candidate)->exists()) {
            $suffixString = '_'.$suffix;
            $candidate = Str::limit($base, 40 - strlen($suffixString), '').$suffixString;
            $suffix++;
        }

        return $candidate;
    }
}
