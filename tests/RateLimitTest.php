<?php

declare(strict_types=1);

final class RateLimitTest extends IntegrationTestCase
{
    public function testLoginRateLimitBlocksSixthFailedAttempt(): void
    {
        $user = $this->registerAndLogin('phpunit_rate');
        $this->api->post('/auth/logout', csrfToken: $user['csrf']);

        for ($i = 0; $i < 5; $i++) {
            $failed = $this->api->post('/auth/login', [
                'username' => $user['username'],
                'password' => 'WrongPassword123!',
            ], expectedStatus: 401);
            self::assertFalse($failed['success']);
        }

        $limited = $this->api->post('/auth/login', [
            'username' => $user['username'],
            'password' => 'WrongPassword123!',
        ], expectedStatus: 429);
        self::assertFalse($limited['success']);
    }
}
