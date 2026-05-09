<?php

declare(strict_types=1);

final class GeneratorTest extends IntegrationTestCase
{
    public function testPasswordGeneratorHonorsCharacterOptions(): void
    {
        $user = $this->registerAndLogin('phpunit_generator');

        $lowerOnly = $this->api->post('/generator/password', [
            'length' => 12,
            'uppercase' => false,
            'lowercase' => true,
            'numbers' => false,
            'symbols' => false,
        ], csrfToken: $user['csrf']);
        self::assertTrue($lowerOnly['success']);
        self::assertMatchesRegularExpression('/^[a-z]{12}$/', $lowerOnly['data']['password']);

        $full = $this->api->post('/generator/password', [
            'length' => 24,
            'uppercase' => true,
            'lowercase' => true,
            'numbers' => true,
            'symbols' => true,
        ], csrfToken: $user['csrf']);
        self::assertTrue($full['success']);
        $password = $full['data']['password'];
        self::assertSame(24, strlen($password));
        self::assertMatchesRegularExpression('/[A-Z]/', $password);
        self::assertMatchesRegularExpression('/[a-z]/', $password);
        self::assertMatchesRegularExpression('/[0-9]/', $password);
        self::assertMatchesRegularExpression('/[!@#$%^&*()\-_=+\[\]{};:,.?\/]/', $password);
    }

    public function testPasswordGeneratorRejectsInvalidInput(): void
    {
        $user = $this->registerAndLogin('phpunit_generator_invalid');

        $tooShort = $this->api->post('/generator/password', [
            'length' => 7,
        ], csrfToken: $user['csrf'], expectedStatus: 422);
        self::assertFalse($tooShort['success']);

        $noGroups = $this->api->post('/generator/password', [
            'length' => 12,
            'uppercase' => false,
            'lowercase' => false,
            'numbers' => false,
            'symbols' => false,
        ], csrfToken: $user['csrf'], expectedStatus: 422);
        self::assertFalse($noGroups['success']);
    }
}
