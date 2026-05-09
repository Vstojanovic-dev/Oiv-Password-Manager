<?php

declare(strict_types=1);

final class SessionManagementTest extends IntegrationTestCase
{
    public function testLogoutAllInvalidatesOtherActiveSessions(): void
    {
        $firstSession = $this->registerAndLogin('phpunit_sessions');

        $secondClient = new ApiClient(getenv('API_BASE') ?: 'http://127.0.0.1:8000/backend/api');
        $secondClient->waitForHealth();
        $secondLogin = $secondClient->post('/auth/login', [
            'username' => $firstSession['username'],
            'password' => $firstSession['password'],
        ]);
        self::assertTrue($secondLogin['success']);

        $secondStatusBefore = $secondClient->get('/auth/status');
        self::assertTrue($secondStatusBefore['data']['authenticated']);

        $logoutAll = $this->api->post('/auth/logout-all', csrfToken: $firstSession['csrf']);
        self::assertTrue($logoutAll['success']);

        $firstStatusAfter = $this->api->get('/auth/status');
        self::assertFalse($firstStatusAfter['data']['authenticated']);

        $secondStatusAfter = $secondClient->get('/auth/status');
        self::assertFalse($secondStatusAfter['data']['authenticated']);

        $protectedAfterInvalidation = $secondClient->get('/accounts', expectedStatus: 401);
        self::assertFalse($protectedAfterInvalidation['success']);

        $freshLogin = $this->api->post('/auth/login', [
            'username' => $firstSession['username'],
            'password' => $firstSession['password'],
        ]);
        self::assertTrue($freshLogin['success']);

        $events = $this->api->get('/auth/security-events');
        self::assertContains('logout_all', array_column($events['data'], 'event_type'));
    }

    public function testChangePasswordInvalidatesOtherActiveSessions(): void
    {
        $firstSession = $this->registerAndLogin('phpunit_password_sessions');

        $secondClient = new ApiClient(getenv('API_BASE') ?: 'http://127.0.0.1:8000/backend/api');
        $secondClient->waitForHealth();
        $secondLogin = $secondClient->post('/auth/login', [
            'username' => $firstSession['username'],
            'password' => $firstSession['password'],
        ]);
        self::assertTrue($secondLogin['success']);

        $changed = $this->api->post('/auth/password', [
            'current_password' => $firstSession['password'],
            'new_password' => 'ChangedMasterPass123!',
        ], csrfToken: $firstSession['csrf']);
        self::assertTrue($changed['success']);

        $firstStatusAfter = $this->api->get('/auth/status');
        self::assertTrue($firstStatusAfter['data']['authenticated']);

        $secondStatusAfter = $secondClient->get('/auth/status');
        self::assertFalse($secondStatusAfter['data']['authenticated']);

        $protectedAfterInvalidation = $secondClient->get('/accounts', expectedStatus: 401);
        self::assertFalse($protectedAfterInvalidation['success']);

        $oldLogin = $secondClient->post('/auth/login', [
            'username' => $firstSession['username'],
            'password' => $firstSession['password'],
        ], expectedStatus: 401);
        self::assertFalse($oldLogin['success']);

        $newLogin = $secondClient->post('/auth/login', [
            'username' => $firstSession['username'],
            'password' => 'ChangedMasterPass123!',
        ]);
        self::assertTrue($newLogin['success']);
    }
}
