<?php

declare(strict_types=1);

final class AccountMetadataTest extends IntegrationTestCase
{
    public function testAccountMetadataIsStoredReturnedUpdatedAndMarkedUsed(): void
    {
        $user = $this->registerAndLogin('phpunit_metadata');

        $created = $this->createAccount($user['csrf'], [
            'site_name' => 'Metadata Site',
            'username' => 'meta@example.com',
            'password' => 'MetadataSecret123!',
            'favorite' => true,
            'category' => 'Work',
            'notes' => 'metadata test',
        ]);

        self::assertTrue($created['favorite']);
        self::assertSame('Work', $created['category']);
        self::assertNull($created['last_used_at']);
        self::assertNotEmpty($created['password_updated_at']);

        $list = $this->api->get('/accounts');
        $listed = $this->findById($list['data'], (int) $created['id']);
        self::assertTrue($listed['favorite']);
        self::assertSame('Work', $listed['category']);
        self::assertArrayNotHasKey('password', $listed);
        self::assertArrayNotHasKey('encrypted_password', $listed);

        $show = $this->api->get('/accounts/' . $created['id']);
        self::assertTrue($show['success']);
        self::assertArrayNotHasKey('password', $show['data']);

        $password = $this->api->get('/accounts/' . $created['id'] . '/password');
        self::assertSame('MetadataSecret123!', $password['data']['password']);

        $updated = $this->api->post('/accounts/' . $created['id'] . '/used', csrfToken: $user['csrf']);
        self::assertTrue($updated['success']);
        self::assertNotEmpty($updated['data']['last_used_at']);
        self::assertArrayNotHasKey('password', $updated['data']);

        $changed = $this->api->requestPutForTests('/accounts/' . $created['id'], [
            'site_name' => 'Metadata Site Updated',
            'site_url' => 'https://metadata.example.com',
            'username' => 'updated@example.com',
            'password' => 'MetadataSecret456!',
            'favorite' => false,
            'category' => 'Personal',
            'notes' => 'updated metadata test',
        ], csrfToken: $user['csrf']);
        self::assertTrue($changed['success']);
        self::assertFalse($changed['data']['favorite']);
        self::assertSame('Personal', $changed['data']['category']);
        self::assertSame('MetadataSecret456!', $changed['data']['password']);
        self::assertNotEmpty($changed['data']['password_updated_at']);

        $changedPassword = $this->api->get('/accounts/' . $created['id'] . '/password');
        self::assertSame('MetadataSecret456!', $changedPassword['data']['password']);
    }

    public function testAccountCategoryHasMaxLength(): void
    {
        $user = $this->registerAndLogin('phpunit_metadata_invalid');

        $response = $this->api->post('/accounts', [
            'site_name' => 'Invalid Category',
            'username' => 'invalid@example.com',
            'password' => 'InvalidSecret123!',
            'category' => str_repeat('x', 65),
        ], csrfToken: $user['csrf'], expectedStatus: 422);

        self::assertFalse($response['success']);
        self::assertStringContainsString('category must be 64 characters or less', $response['error']);
    }

    public function testSiteUrlWithoutSchemeIsNormalized(): void
    {
        $user = $this->registerAndLogin('phpunit_url_normalize');

        $created = $this->createAccount($user['csrf'], [
            'site_name' => 'YouTube',
            'site_url' => 'www.youtube.com',
            'username' => 'youtube@example.com',
            'password' => 'YouTubeSecret123!',
        ]);

        self::assertSame('https://www.youtube.com', $created['site_url']);
    }

    private function findById(array $accounts, int $id): array
    {
        foreach ($accounts as $account) {
            if ((int) $account['id'] === $id) {
                return $account;
            }
        }

        self::fail('Account not found in list response.');
    }
}
