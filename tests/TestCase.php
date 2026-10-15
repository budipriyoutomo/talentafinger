<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * actingAs() berganti user TANPA login/logout, sedangkan sesi test tetap
     * menyimpan hash password user sebelumnya. Middleware AuthenticateSession
     * lalu menganggapnya sesi curian dan me-logout (401). Buang hash itu supaya
     * ganti user di test setara dengan login baru.
     */
    public function be(UserContract $user, $guard = null)
    {
        $this->app['session']->forget('password_hash_'.($guard ?? $this->app['auth']->getDefaultDriver()));

        return parent::be($user, $guard);
    }
}
