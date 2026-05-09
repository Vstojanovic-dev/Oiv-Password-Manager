<?php

declare(strict_types=1);

final class PasswordPolicyTest extends IntegrationTestCase
{
    public function testRegisterBlocksTooShortAndCommonMasterPasswords(): void
    {
        $tooShort = $this->api->post('/auth/register', [
            'username' => 'phpunit_short_' . time() . '_' . random_int(1000, 9999),
            'password' => 'Short1!',
        ], expectedStatus: 422);
        self::assertFalse($tooShort['success']);
        self::assertStringContainsString('at least 10 characters', $tooShort['error']);

        $blocked = $this->api->post('/auth/register', [
            'username' => 'phpunit_blocked_' . time() . '_' . random_int(1000, 9999),
            'password' => 'Password123',
        ], expectedStatus: 422);
        self::assertFalse($blocked['success']);
        self::assertStringContainsString('too common', $blocked['error']);

        $blockedTopCommon = $this->api->post('/auth/register', [
            'username' => 'phpunit_blocked_top_' . time() . '_' . random_int(1000, 9999),
            'password' => 'Aa123456',
        ], expectedStatus: 422);
        self::assertFalse($blockedTopCommon['success']);
        self::assertStringContainsString('too common', $blockedTopCommon['error']);
    }

    public function testRegisterBlocksMasterPasswordEqualToUsername(): void
    {
        $response = $this->api->post('/auth/register', [
            'username' => 'sameuser123',
            'password' => 'sameuser123',
        ], expectedStatus: 422);

        self::assertFalse($response['success']);
        self::assertStringContainsString('different from the username', $response['error']);
    }

    public function testRegisterAllowsWeakMasterPasswordWithWarningsAndStrongWithoutWarnings(): void
    {
        $weak = $this->api->post('/auth/register', [
            'username' => 'phpunit_weak_' . time() . '_' . random_int(1000, 9999),
            'password' => 'WeakpassAA',
        ], expectedStatus: 201);
        self::assertTrue($weak['success']);
        self::assertSame('weak', $weak['data']['password_strength']);
        self::assertNotEmpty($weak['data']['password_warnings']);

        $strongClient = new ApiClient(getenv('API_BASE') ?: 'http://127.0.0.1:8000/backend/api');
        $strong = $strongClient->post('/auth/register', [
            'username' => 'phpunit_strong_' . time() . '_' . random_int(1000, 9999),
            'password' => 'StrongMaster123!',
        ], expectedStatus: 201);
        self::assertTrue($strong['success']);
        self::assertSame('strong', $strong['data']['password_strength']);
        self::assertSame([], $strong['data']['password_warnings']);
    }

    public function testChangePasswordBlocksSamePasswordAndReturnsWeakWarnings(): void
    {
        $user = $this->registerAndLogin('phpunit_policy_change');

        $same = $this->api->post('/auth/password', [
            'current_password' => $user['password'],
            'new_password' => $user['password'],
        ], csrfToken: $user['csrf'], expectedStatus: 422);
        self::assertFalse($same['success']);
        self::assertStringContainsString('different', $same['error']);

        $sameAsUsername = $this->api->post('/auth/password', [
            'current_password' => $user['password'],
            'new_password' => $user['username'],
        ], csrfToken: $user['csrf'], expectedStatus: 422);
        self::assertFalse($sameAsUsername['success']);
        self::assertStringContainsString('different from the username', $sameAsUsername['error']);

        $changed = $this->api->post('/auth/password', [
            'current_password' => $user['password'],
            'new_password' => 'WeakpassAA',
        ], csrfToken: $user['csrf']);
        self::assertTrue($changed['success']);
        self::assertSame('weak', $changed['data']['password_strength']);
        self::assertNotEmpty($changed['data']['password_warnings']);
    }

    public function testVaultPasswordWarningsForWeakAndReusedPasswords(): void
    {
        $user = $this->registerAndLogin('phpunit_vault_policy');

        $weak = $this->api->post('/accounts', [
            'site_name' => 'Weak Vault Password',
            'username' => 'weak@example.com',
            'password' => 'password123',
        ], csrfToken: $user['csrf'], expectedStatus: 201);
        self::assertTrue($weak['success']);
        self::assertSame('weak', $weak['data']['password_strength']);
        self::assertNotEmpty($weak['data']['password_warnings']);

        $sharedPassword = 'SameVaultPass123!';
        $first = $this->api->post('/accounts', [
            'site_name' => 'First Shared',
            'username' => 'first@example.com',
            'password' => $sharedPassword,
        ], csrfToken: $user['csrf'], expectedStatus: 201);
        self::assertTrue($first['success']);
        self::assertSame([], $first['data']['password_warnings']);

        $second = $this->api->post('/accounts', [
            'site_name' => 'Second Shared',
            'username' => 'second@example.com',
            'password' => $sharedPassword,
        ], csrfToken: $user['csrf'], expectedStatus: 201);
        self::assertTrue($second['success']);
        self::assertContains('This vault password is already used on another saved account.', $second['data']['password_warnings']);
    }
}
