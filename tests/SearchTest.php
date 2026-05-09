<?php

declare(strict_types=1);

final class SearchTest extends IntegrationTestCase
{
    public function testAccountSearchMatchesSiteUrlAndUsername(): void
    {
        $user = $this->registerAndLogin('phpunit_search');

        $this->createAccount($user['csrf'], [
            'site_name' => 'GitHub',
            'site_url' => 'https://github.com',
            'username' => 'octo@example.com',
            'password' => 'GitHubSecret123!',
        ]);
        $this->createAccount($user['csrf'], [
            'site_name' => 'Email',
            'site_url' => 'https://mail.example.com',
            'username' => 'mailbox@example.com',
            'password' => 'EmailSecret123!',
        ]);

        $bySite = $this->api->get('/accounts?search=GitHub');
        self::assertCount(1, $bySite['data']);
        self::assertSame('GitHub', $bySite['data'][0]['site_name']);

        $byUrl = $this->api->get('/accounts?search=mail.example');
        self::assertCount(1, $byUrl['data']);
        self::assertSame('Email', $byUrl['data'][0]['site_name']);

        $byUsername = $this->api->get('/accounts?search=octo');
        self::assertCount(1, $byUsername['data']);
        self::assertArrayNotHasKey('password', $byUsername['data'][0]);

        $password = $this->api->get('/accounts/' . $byUsername['data'][0]['id'] . '/password');
        self::assertSame('GitHubSecret123!', $password['data']['password']);
    }
}
