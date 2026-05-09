<?php

declare(strict_types=1);

final class ProductLiteFeaturesTest extends IntegrationTestCase
{
    public function testSoftDeleteTrashRestorePermanentDeleteAndFilters(): void
    {
        $user = $this->registerAndLogin('phpunit_product_lite');
        $work = $this->createAccount($user['csrf'], [
            'site_name' => 'Work Favorite',
            'username' => 'work@example.com',
            'password' => 'WorkFavoriteSecret123!',
            'favorite' => true,
            'category' => 'Work',
        ]);
        $personal = $this->createAccount($user['csrf'], [
            'site_name' => 'Personal Entry',
            'username' => 'personal@example.com',
            'password' => 'PersonalSecret123!',
            'favorite' => false,
            'category' => 'Personal',
        ]);

        $favoriteFilter = $this->api->get('/accounts?favorite=1');
        self::assertCount(1, $favoriteFilter['data']);
        self::assertSame($work['id'], $favoriteFilter['data'][0]['id']);

        $categoryFilter = $this->api->get('/accounts?category=Personal');
        self::assertCount(1, $categoryFilter['data']);
        self::assertSame($personal['id'], $categoryFilter['data'][0]['id']);

        $deleted = $this->api->deleteForTests('/accounts/' . $work['id'], csrfToken: $user['csrf']);
        self::assertTrue($deleted['success']);

        $active = $this->api->get('/accounts');
        self::assertCount(0, array_filter($active['data'], static fn (array $account): bool => (int) $account['id'] === (int) $work['id']));

        $trash = $this->api->get('/accounts/trash');
        self::assertCount(1, $trash['data']);
        self::assertSame($work['id'], $trash['data'][0]['id']);
        self::assertNotEmpty($trash['data'][0]['deleted_at']);

        $restored = $this->api->post('/accounts/' . $work['id'] . '/restore', csrfToken: $user['csrf']);
        self::assertTrue($restored['success']);
        self::assertNull($restored['data']['deleted_at']);

        $this->api->deleteForTests('/accounts/' . $work['id'], csrfToken: $user['csrf']);
        $permanent = $this->api->deleteForTests('/accounts/' . $work['id'] . '/permanent', csrfToken: $user['csrf']);
        self::assertTrue($permanent['success']);
        $missing = $this->api->get('/accounts/' . $work['id'], expectedStatus: 404);
        self::assertFalse($missing['success']);
    }

    public function testPasswordHistoryAndSecurityReport(): void
    {
        $user = $this->registerAndLogin('phpunit_report');
        $created = $this->createAccount($user['csrf'], [
            'site_name' => 'Weak Duplicate One',
            'site_url' => null,
            'username' => 'one@example.com',
            'password' => 'password123',
        ]);
        $this->createAccount($user['csrf'], [
            'site_name' => 'Weak Duplicate Two',
            'site_url' => null,
            'username' => 'two@example.com',
            'password' => 'password123',
        ]);

        $changed = $this->api->requestPutForTests('/accounts/' . $created['id'], [
            'site_name' => 'Weak Duplicate One',
            'site_url' => null,
            'username' => 'one@example.com',
            'password' => 'HistorySecret123!',
            'favorite' => false,
            'category' => null,
            'notes' => null,
        ], csrfToken: $user['csrf']);
        self::assertTrue($changed['success']);

        $history = $this->api->get('/accounts/' . $created['id'] . '/history');
        self::assertCount(1, $history['data']);
        self::assertSame('password123', $history['data'][0]['password']);

        $report = $this->api->get('/accounts/password-report');
        self::assertTrue($report['success']);
        self::assertGreaterThanOrEqual(1, $report['data']['weak_count']);
        self::assertGreaterThanOrEqual(1, $report['data']['missing_url_count']);
    }

    public function testSessionListAndSingleSessionRevoke(): void
    {
        $firstSession = $this->registerAndLogin('phpunit_session_list');

        $secondClient = new ApiClient(getenv('API_BASE') ?: 'http://127.0.0.1:8000/backend/api');
        $secondClient->waitForHealth();
        $secondLogin = $secondClient->post('/auth/login', [
            'username' => $firstSession['username'],
            'password' => $firstSession['password'],
        ]);
        self::assertTrue($secondLogin['success']);

        $sessions = $this->api->get('/auth/sessions');
        self::assertGreaterThanOrEqual(2, count($sessions['data']));
        $other = null;
        foreach ($sessions['data'] as $session) {
            if (!$session['current'] && $session['revoked_at'] === null) {
                $other = $session;
                break;
            }
        }
        self::assertNotNull($other);

        $revoked = $this->api->deleteForTests('/auth/sessions/' . $other['id'], csrfToken: $firstSession['csrf']);
        self::assertTrue($revoked['success']);

        $secondStatus = $secondClient->get('/auth/status');
        self::assertFalse($secondStatus['data']['authenticated']);
    }
}
