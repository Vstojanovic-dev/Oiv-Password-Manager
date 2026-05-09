<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected ApiClient $api;

    protected function setUp(): void
    {
        $baseUrl = getenv('API_BASE') ?: 'http://127.0.0.1:8000/backend/api';
        $this->api = new ApiClient($baseUrl);
        $this->api->waitForHealth();
    }

    /** @return array{username: string, password: string, csrf: string} */
    protected function registerAndLogin(string $prefix = 'phpunit_user'): array
    {
        $username = $prefix . '_' . time() . '_' . random_int(1000, 9999);
        $password = 'MasterPass123!';

        $registered = $this->api->post('/auth/register', [
            'username' => $username,
            'password' => $password,
        ], expectedStatus: 201);

        self::assertTrue($registered['success']);
        self::assertIsString($registered['data']['csrf_token'] ?? null);

        return [
            'username' => $username,
            'password' => $password,
            'csrf' => $registered['data']['csrf_token'],
        ];
    }

    /** @return array<string, mixed> */
    protected function createAccount(string $csrf, array $overrides = []): array
    {
        $payload = array_merge([
            'site_name' => 'Example',
            'site_url' => 'https://example.com',
            'username' => 'demo@example.com',
            'password' => 'VaultSecret123!',
            'favorite' => false,
            'category' => null,
        ], $overrides);

        $created = $this->api->post('/accounts', $payload, csrfToken: $csrf, expectedStatus: 201);
        self::assertTrue($created['success']);

        return $created['data'];
    }

    protected function backupPath(string $filename): string
    {
        return rtrim(getenv('BACKUP_DIR') ?: dirname(__DIR__, 2) . '/data/www/backend/storage/backups', '/\\') . DIRECTORY_SEPARATOR . $filename;
    }
}
