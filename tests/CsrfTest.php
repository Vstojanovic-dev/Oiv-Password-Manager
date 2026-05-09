<?php

declare(strict_types=1);

final class CsrfTest extends IntegrationTestCase
{
    public function testProtectedMutatingRoutesRejectMissingCsrfToken(): void
    {
        $this->registerAndLogin('phpunit_csrf');

        $response = $this->api->post('/accounts', [
            'site_name' => 'No CSRF',
            'username' => 'csrf@example.com',
            'password' => 'CsrfSecret123!',
        ], expectedStatus: 403);

        self::assertFalse($response['success']);
        self::assertSame('Invalid CSRF token.', $response['error']);
    }

    public function testAuthEntryRoutesDoNotRequireCsrfToken(): void
    {
        $username = 'phpunit_csrf_login_' . time() . '_' . random_int(1000, 9999);
        $password = 'MasterPass123!';

        $registered = $this->api->post('/auth/register', [
            'username' => $username,
            'password' => $password,
        ], expectedStatus: 201);
        self::assertTrue($registered['success']);
        $csrf = $registered['data']['csrf_token'];
        $this->api->post('/auth/logout', csrfToken: $csrf);

        $login = $this->api->post('/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        self::assertTrue($login['success']);
    }
}
