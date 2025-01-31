<?php

namespace LdapRecord\Laravel\Auth;

use Illuminate\Support\Facades\Auth;

trait CreatesUserProvider
{
    /**
     * Attempt to retrieve the authenticated guard name.
     *
     * @return string|null
     */
    protected function getCurrentAuthGuard()
    {
        foreach (config('auth.guards') as $guard => $config) {
            if (Auth::guard($guard)->check()) {
                return $guard;
            }
        }
    }

    /**
     * Get the guard's authentication user provider.
     *
     * @param string $guard
     *
     * @return \Illuminate\Contracts\Auth\UserProvider|null
     */
    protected function getCurrentAuthProvider($guard)
    {
        return Auth::createUserProvider(
            config("auth.guards.$guard.provider")
        );
    }
}
