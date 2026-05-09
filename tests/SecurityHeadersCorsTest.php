<?php

declare(strict_types=1);

final class SecurityHeadersCorsTest extends IntegrationTestCase
{
    public function testSecurityHeadersArePresentOnApiResponses(): void
    {
        $response = $this->api->rawForTests('GET', '/health');
        $headers = $response['headers'];

        self::assertSame('nosniff', $headers['x-content-type-options'] ?? null);
        self::assertSame('no-referrer', $headers['referrer-policy'] ?? null);
        self::assertSame('DENY', $headers['x-frame-options'] ?? null);
        self::assertSame("default-src 'none'; frame-ancestors 'none';", $headers['content-security-policy'] ?? null);
        self::assertStringContainsString('no-store', $headers['cache-control'] ?? '');
        self::assertStringContainsString('camera=()', $headers['permissions-policy'] ?? '');
    }

    public function testAllowedOriginGetsCorsAllowOriginHeader(): void
    {
        $response = $this->api->rawForTests('GET', '/health', [
            'Origin: http://localhost:3000',
        ]);
        $headers = $response['headers'];

        self::assertSame('http://localhost:3000', $headers['access-control-allow-origin'] ?? null);
        self::assertSame('true', $headers['access-control-allow-credentials'] ?? null);
        self::assertSame('Origin', $headers['vary'] ?? null);
    }

    public function testDisallowedOriginDoesNotGetCorsAllowOriginHeader(): void
    {
        $response = $this->api->rawForTests('GET', '/health', [
            'Origin: http://evil.example',
        ]);
        $headers = $response['headers'];

        self::assertArrayNotHasKey('access-control-allow-origin', $headers);
        self::assertSame('true', $headers['access-control-allow-credentials'] ?? null);
    }

    public function testOptionsPreflightForAllowedOrigin(): void
    {
        $response = $this->api->rawForTests('OPTIONS', '/accounts', [
            'Origin: http://127.0.0.1:3000',
            'Access-Control-Request-Method: POST',
            'Access-Control-Request-Headers: Content-Type, X-CSRF-Token',
        ]);
        $headers = $response['headers'];

        self::assertSame('http://127.0.0.1:3000', $headers['access-control-allow-origin'] ?? null);
        self::assertStringContainsString('POST', $headers['access-control-allow-methods'] ?? '');
        self::assertStringContainsString('X-CSRF-Token', $headers['access-control-allow-headers'] ?? '');
    }
}
