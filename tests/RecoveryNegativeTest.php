<?php

declare(strict_types=1);

final class RecoveryNegativeTest extends IntegrationTestCase
{
    public function testRecoveryRejectsWrongCodeBadTokenAndShortNewPassword(): void
    {
        $user = $this->registerAndLogin('phpunit_recovery_negative');

        $codes = $this->api->post('/auth/recovery-codes', [
            'current_password' => $user['password'],
        ], csrfToken: $user['csrf'], expectedStatus: 201);
        $validCode = $codes['data']['codes'][0];

        $this->api->post('/auth/logout', csrfToken: $user['csrf']);

        $wrongCode = $this->api->post('/auth/forgot/verify', [
            'username' => $user['username'],
            'recovery_code' => 'AAAA-BBBB-CCCC-DDDD',
        ], expectedStatus: 401);
        self::assertFalse($wrongCode['success']);

        $badToken = $this->api->post('/auth/forgot/reset', [
            'reset_token' => str_repeat('a', 64),
            'new_password' => 'NewMasterPass123!',
        ], expectedStatus: 401);
        self::assertFalse($badToken['success']);

        $verify = $this->api->post('/auth/forgot/verify', [
            'username' => $user['username'],
            'recovery_code' => $validCode,
        ]);
        self::assertTrue($verify['success']);

        $shortPassword = $this->api->post('/auth/forgot/reset', [
            'reset_token' => $verify['data']['reset_token'],
            'new_password' => 'short',
        ], expectedStatus: 422);
        self::assertFalse($shortPassword['success']);
    }

    public function testRecoveryVerifyIsRateLimitedAndSuccessClearsAttempts(): void
    {
        $user = $this->registerAndLogin('phpunit_recovery_limit');
        $codes = $this->api->post('/auth/recovery-codes', [
            'current_password' => $user['password'],
        ], csrfToken: $user['csrf'], expectedStatus: 201);
        $validCode = $codes['data']['codes'][0];
        $this->api->post('/auth/logout', csrfToken: $user['csrf']);

        for ($i = 0; $i < 4; $i++) {
            $failed = $this->api->post('/auth/forgot/verify', [
                'username' => $user['username'],
                'recovery_code' => 'AAAA-BBBB-CCCC-DDDD',
            ], expectedStatus: 401);
            self::assertFalse($failed['success']);
        }

        $valid = $this->api->post('/auth/forgot/verify', [
            'username' => $user['username'],
            'recovery_code' => $validCode,
        ]);
        self::assertTrue($valid['success']);

        $afterClear = $this->api->post('/auth/forgot/verify', [
            'username' => $user['username'],
            'recovery_code' => 'AAAA-BBBB-CCCC-DDDD',
        ], expectedStatus: 401);
        self::assertFalse($afterClear['success']);

        $blockedUser = $this->registerAndLogin('phpunit_recovery_blocked');
        $this->api->post('/auth/logout', csrfToken: $blockedUser['csrf']);

        for ($i = 0; $i < 5; $i++) {
            $failed = $this->api->post('/auth/forgot/verify', [
                'username' => $blockedUser['username'],
                'recovery_code' => 'AAAA-BBBB-CCCC-DDDD',
            ], expectedStatus: 401);
            self::assertFalse($failed['success']);
        }

        $limited = $this->api->post('/auth/forgot/verify', [
            'username' => $blockedUser['username'],
            'recovery_code' => 'AAAA-BBBB-CCCC-DDDD',
        ], expectedStatus: 429);
        self::assertFalse($limited['success']);
        self::assertSame('Too many recovery attempts. Try again later.', $limited['error']);
    }

    public function testRecoveryResetBadTokenIsRateLimited(): void
    {
        $invalidToken = bin2hex(random_bytes(32));

        for ($i = 0; $i < 5; $i++) {
            $failed = $this->api->post('/auth/forgot/reset', [
                'reset_token' => $invalidToken,
                'new_password' => 'NewMasterPass123!',
            ], expectedStatus: 401);
            self::assertFalse($failed['success']);
        }

        $limited = $this->api->post('/auth/forgot/reset', [
            'reset_token' => $invalidToken,
            'new_password' => 'NewMasterPass123!',
        ], expectedStatus: 429);
        self::assertFalse($limited['success']);
        self::assertSame('Too many recovery attempts. Try again later.', $limited['error']);
    }
}
