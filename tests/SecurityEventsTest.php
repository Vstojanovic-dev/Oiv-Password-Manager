<?php

declare(strict_types=1);

final class SecurityEventsTest extends IntegrationTestCase
{
    public function testSecurityEventsAreRecordedAndDoNotExposeSecrets(): void
    {
        $user = $this->registerAndLogin('phpunit_events');
        $vaultPassword = 'EventVaultSecret123!';
        $backupPassword = 'EventBackupPassword123!';

        $this->createAccount($user['csrf'], [
            'site_name' => 'Audit Backup Site',
            'username' => 'audit@example.com',
            'password' => $vaultPassword,
        ]);

        $backup = $this->api->post('/backup', [
            'backup_password' => $backupPassword,
        ], csrfToken: $user['csrf'], expectedStatus: 201);
        self::assertTrue($backup['success']);
        $backupPath = $this->backupPath($backup['data']['filename']);

        $failedRestore = $this->api->multipart(
            '/backup/restore',
            ['backup_password' => 'WrongBackupPassword123!'],
            'backup_file',
            $backupPath,
            csrfToken: $user['csrf'],
            expectedStatus: 422
        );
        self::assertFalse($failedRestore['success']);

        $codes = $this->api->post('/auth/recovery-codes', [
            'current_password' => $user['password'],
        ], csrfToken: $user['csrf'], expectedStatus: 201);
        self::assertTrue($codes['success']);
        $recoveryCode = $codes['data']['codes'][0];

        $this->api->post('/auth/logout', csrfToken: $user['csrf']);

        $failedLogin = $this->api->post('/auth/login', [
            'username' => $user['username'],
            'password' => 'WrongMasterPassword123!',
        ], expectedStatus: 401);
        self::assertFalse($failedLogin['success']);

        $login = $this->api->post('/auth/login', [
            'username' => $user['username'],
            'password' => $user['password'],
        ]);
        self::assertTrue($login['success']);

        $events = $this->api->get('/auth/security-events');
        self::assertTrue($events['success']);
        $types = array_column($events['data'], 'event_type');

        foreach ([
            'register_success',
            'login_success',
            'login_failed',
            'logout',
            'recovery_codes_generated',
            'backup_created',
            'backup_restore_failed',
        ] as $expectedType) {
            self::assertContains($expectedType, $types);
        }

        $serializedEvents = json_encode($events['data'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($user['password'], $serializedEvents);
        self::assertStringNotContainsString($vaultPassword, $serializedEvents);
        self::assertStringNotContainsString($backupPassword, $serializedEvents);
        self::assertStringNotContainsString($recoveryCode, $serializedEvents);
    }
}
