<?php

declare(strict_types=1);

final class BackupRestoreTest extends IntegrationTestCase
{
    public function testEncryptedBackupDoesNotExposePlaintextAndRestoreRequiresCorrectPassword(): void
    {
        $user = $this->registerAndLogin('phpunit_backup');
        $siteName = 'Backup Site ' . random_int(1000, 9999);
        $sitePassword = 'BackupSecretValue123!';
        $backupPassword = 'BackupPassword123!';

        $created = $this->createAccount($user['csrf'], [
            'site_name' => $siteName,
            'site_url' => 'https://backup.example.com',
            'username' => 'backup@example.com',
            'password' => $sitePassword,
            'favorite' => true,
            'category' => 'Work',
        ]);
        $used = $this->api->post('/accounts/' . $created['id'] . '/used', csrfToken: $user['csrf']);
        self::assertTrue($used['success']);

        $backup = $this->api->post('/backup', [
            'backup_password' => $backupPassword,
        ], csrfToken: $user['csrf'], expectedStatus: 201);

        self::assertTrue($backup['success']);
        self::assertSame(1, $backup['data']['count']);
        $filename = $backup['data']['filename'];
        self::assertIsString($filename);

        $backupPath = $this->backupPath($filename);
        self::assertFileExists($backupPath);
        $rawBackup = file_get_contents($backupPath);
        self::assertIsString($rawBackup);
        self::assertStringNotContainsString($sitePassword, $rawBackup);
        self::assertStringNotContainsString($siteName, $rawBackup);

        $directAccess = $this->api->rawTextForTests('GET', '/../backups/' . rawurlencode($filename), expectedStatus: 403);
        self::assertNotSame(200, $directAccess['status']);

        $wrongRestore = $this->api->multipart(
            '/backup/restore',
            ['backup_password' => 'WrongBackupPassword123!'],
            'backup_file',
            $backupPath,
            csrfToken: $user['csrf'],
            expectedStatus: 422
        );
        self::assertFalse($wrongRestore['success']);

        $restore = $this->api->multipart(
            '/backup/restore',
            ['backup_password' => $backupPassword],
            'backup_file',
            $backupPath,
            csrfToken: $user['csrf']
        );
        self::assertTrue($restore['success']);
        self::assertSame(1, $restore['data']['imported']);
        self::assertSame(0, $restore['data']['skipped']);

        $accounts = $this->api->get('/accounts');
        $matching = array_values(array_filter(
            $accounts['data'],
            static fn (array $account): bool => ($account['site_name'] ?? null) === $siteName
        ));
        self::assertCount(2, $matching, 'Restore should import a second readable copy of the backed-up entry.');
        foreach ($matching as $account) {
            $password = $this->api->get('/accounts/' . $account['id'] . '/password');
            self::assertSame($sitePassword, $password['data']['password']);
            self::assertTrue($account['favorite']);
            self::assertSame('Work', $account['category']);
            self::assertNotEmpty($account['last_used_at']);
            self::assertNotEmpty($account['password_updated_at']);
        }
    }

    public function testRestoreRejectsInvalidBackupUploadShape(): void
    {
        $user = $this->registerAndLogin('phpunit_backup_upload_validation');

        $wrongExtension = sys_get_temp_dir() . '/backup_' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($wrongExtension, '{"not":"a real encrypted backup"}');
        try {
            $badExtension = $this->api->multipart(
                '/backup/restore',
                ['backup_password' => 'BackupPassword123!'],
                'backup_file',
                $wrongExtension,
                csrfToken: $user['csrf'],
                expectedStatus: 422
            );
            self::assertFalse($badExtension['success']);
            self::assertStringContainsString('.json', $badExtension['error']);
        } finally {
            @unlink($wrongExtension);
        }

        $largeBackup = sys_get_temp_dir() . '/backup_' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($largeBackup, str_repeat('A', (2 * 1024 * 1024) + 1));
        try {
            $tooLarge = $this->api->multipart(
                '/backup/restore',
                ['backup_password' => 'BackupPassword123!'],
                'backup_file',
                $largeBackup,
                csrfToken: $user['csrf'],
                expectedStatus: 413
            );
            self::assertFalse($tooLarge['success']);
        } finally {
            @unlink($largeBackup);
        }
    }
}
