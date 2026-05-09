<?php

declare(strict_types=1);

final class RecoveryFlowTest extends IntegrationTestCase
{
    public function testRecoveryResetPreservesVaultPasswordAndRejectsOldMasterPassword(): void
    {
        $username = 'phpunit_recovery_' . time() . '_' . random_int(1000, 9999);
        $oldPassword = 'OldMasterPass123!';
        $newPassword = 'NewMasterPass123!';
        $siteName = 'Example Login';
        $siteUsername = 'demo@example.com';
        $sitePassword = 'PreservedSitePassword123!';

        $register = $this->api->post('/auth/register', [
            'username' => $username,
            'password' => $oldPassword,
        ], expectedStatus: 201);
        self::assertTrue($register['success']);
        $csrf = $register['data']['csrf_token'] ?? null;
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);

        $this->api->post('/auth/logout', csrfToken: $csrf);

        $login = $this->api->post('/auth/login', [
            'username' => $username,
            'password' => $oldPassword,
        ]);
        self::assertTrue($login['success']);
        $csrf = $login['data']['csrf_token'] ?? null;
        self::assertIsString($csrf);

        $created = $this->api->post('/accounts', [
            'site_name' => $siteName,
            'site_url' => 'https://example.com',
            'username' => $siteUsername,
            'password' => $sitePassword,
        ], csrfToken: $csrf, expectedStatus: 201);
        self::assertTrue($created['success']);
        self::assertSame($sitePassword, $created['data']['password']);

        $codes = $this->api->post('/auth/recovery-codes', [
            'current_password' => $oldPassword,
        ], csrfToken: $csrf, expectedStatus: 201);
        self::assertTrue($codes['success']);
        self::assertCount(8, $codes['data']['codes']);
        $recoveryCode = $codes['data']['codes'][0];

        $staleClient = new ApiClient(getenv('API_BASE') ?: 'http://127.0.0.1:8000/backend/api');
        $staleClient->waitForHealth();
        $staleLogin = $staleClient->post('/auth/login', [
            'username' => $username,
            'password' => $oldPassword,
        ]);
        self::assertTrue($staleLogin['success']);

        $this->api->post('/auth/logout', csrfToken: $csrf);

        $verify = $this->api->post('/auth/forgot/verify', [
            'username' => $username,
            'recovery_code' => $recoveryCode,
        ]);
        self::assertTrue($verify['success']);
        $resetToken = $verify['data']['reset_token'] ?? null;
        self::assertIsString($resetToken);
        self::assertNotSame('', $resetToken);

        $reset = $this->api->post('/auth/forgot/reset', [
            'reset_token' => $resetToken,
            'new_password' => $newPassword,
        ]);
        self::assertTrue($reset['success']);
        $csrf = $reset['data']['csrf_token'] ?? null;
        self::assertIsString($csrf);

        $staleStatus = $staleClient->get('/auth/status');
        self::assertFalse($staleStatus['data']['authenticated']);
        $staleProtected = $staleClient->get('/accounts', expectedStatus: 401);
        self::assertFalse($staleProtected['success']);

        $this->api->post('/auth/logout', csrfToken: $csrf);

        $oldLogin = $this->api->post('/auth/login', [
            'username' => $username,
            'password' => $oldPassword,
        ], expectedStatus: 401);
        self::assertFalse($oldLogin['success']);

        $newLogin = $this->api->post('/auth/login', [
            'username' => $username,
            'password' => $newPassword,
        ]);
        self::assertTrue($newLogin['success']);

        $accounts = $this->api->get('/accounts');
        self::assertTrue($accounts['success']);

        $entry = null;
        foreach ($accounts['data'] as $account) {
            if (($account['site_name'] ?? null) === $siteName) {
                $entry = $account;
                break;
            }
        }

        self::assertNotNull($entry, 'Vault entry was not found after recovery reset.');
        self::assertSame($siteUsername, $entry['username']);
        self::assertArrayNotHasKey('password', $entry);
        $password = $this->api->get('/accounts/' . $entry['id'] . '/password');
        self::assertSame($sitePassword, $password['data']['password']);

        $reused = $this->api->post('/auth/forgot/verify', [
            'username' => $username,
            'recovery_code' => $recoveryCode,
        ], expectedStatus: 401);
        self::assertFalse($reused['success'], 'Used recovery code should not work twice.');
    }
}
