<?php

declare(strict_types=1);

final class ApiClient
{
    private string $baseUrl;

    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function waitForHealth(): void
    {
        $lastError = null;
        for ($i = 0; $i < 40; $i++) {
            try {
                $health = $this->get('/health');
                if (($health['success'] ?? false) && (($health['data']['database'] ?? null) === 'ok')) {
                    return;
                }
            } catch (Throwable $e) {
                $lastError = $e;
            }
            sleep(2);
        }

        throw new RuntimeException('API did not become healthy. ' . ($lastError?->getMessage() ?? ''));
    }

    /** @return array<string, mixed> */
    public function get(string $path, int $expectedStatus = 200): array
    {
        return $this->request('GET', $path, null, null, $expectedStatus);
    }

    /** @return array<string, mixed> */
    public function post(string $path, ?array $body = null, ?string $csrfToken = null, int $expectedStatus = 200): array
    {
        return $this->request('POST', $path, $body, $csrfToken, $expectedStatus);
    }

    /** @return array<string, mixed> */
    public function requestPutForTests(string $path, array $body, ?string $csrfToken = null, int $expectedStatus = 200): array
    {
        return $this->request('PUT', $path, $body, $csrfToken, $expectedStatus);
    }

    /** @return array<string, mixed> */
    public function deleteForTests(string $path, ?string $csrfToken = null, int $expectedStatus = 200): array
    {
        return $this->request('DELETE', $path, null, $csrfToken, $expectedStatus);
    }

    /** @return array{status: int, json: array<string, mixed>|null, headers: array<string, string>} */
    public function rawForTests(string $method, string $path, array $headers = [], ?string $body = null, int $expectedStatus = 200): array
    {
        $raw = $this->rawTextForTests($method, $path, $headers, $body, $expectedStatus);

        return [
            'status' => $raw['status'],
            'json' => $raw['body'] === '' ? null : json_decode($raw['body'], true, flags: JSON_THROW_ON_ERROR),
            'headers' => $raw['headers'],
        ];
    }

    /** @return array{status: int, body: string, headers: array<string, string>} */
    public function rawTextForTests(string $method, string $path, array $headers = [], ?string $body = null, int $expectedStatus = 200): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $responseHeaders = [];
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException($message, $severity);
        });
        try {
            $response = file_get_contents($this->baseUrl . $path, false, $context);
            $responseHeaders = $http_response_header ?? [];
        } finally {
            restore_error_handler();
        }

        if ($response === false) {
            throw new RuntimeException($method . ' ' . $path . ' returned no response.');
        }

        $status = $this->statusCode($responseHeaders);
        if ($status !== $expectedStatus) {
            throw new RuntimeException(sprintf(
                'Expected HTTP %d for %s %s, got %d. Payload: %s',
                $expectedStatus,
                $method,
                $path,
                $status,
                $response
            ));
        }

        return [
            'status' => $status,
            'body' => $response,
            'headers' => $this->normalizeHeaders($responseHeaders),
        ];
    }

    /** @return array<string, mixed> */
    public function multipart(
        string $path,
        array $fields,
        string $fileField,
        string $filePath,
        ?string $csrfToken = null,
        int $expectedStatus = 200
    ): array {
        $boundary = '----phpunit-' . bin2hex(random_bytes(12));
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $body .= (string) $value . "\r\n";
        }

        $filename = basename($filePath);
        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
            throw new RuntimeException('Could not read upload fixture: ' . $filePath);
        }

        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $fileField . '"; filename="' . $filename . '"' . "\r\n";
        $body .= "Content-Type: application/json\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= "--{$boundary}--\r\n";

        return $this->requestRaw('POST', $path, $body, [
            'Accept: application/json',
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ], $csrfToken, $expectedStatus);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?array $body, ?string $csrfToken, int $expectedStatus): array
    {
        $headers = [
            'Accept: application/json',
        ];

        $content = null;
        if ($body !== null) {
            $content = json_encode($body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($content);
        }

        return $this->requestRaw($method, $path, $content ?? '', $headers, $csrfToken, $expectedStatus);
    }

    /** @param array<int, string> $headers */
    private function requestRaw(
        string $method,
        string $path,
        string $content,
        array $headers,
        ?string $csrfToken,
        int $expectedStatus
    ): array {
        if ($csrfToken !== null) {
            $headers[] = 'X-CSRF-Token: ' . $csrfToken;
        }

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $responseHeaders = [];
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException($message, $severity);
        });
        try {
            $response = file_get_contents($this->baseUrl . $path, false, $context);
            $responseHeaders = $http_response_header ?? [];
        } finally {
            restore_error_handler();
        }

        if ($response === false) {
            throw new RuntimeException($method . ' ' . $path . ' returned no response.');
        }

        $status = $this->statusCode($responseHeaders);
        $this->storeCookies($responseHeaders);
        $json = json_decode($response, true, flags: JSON_THROW_ON_ERROR);

        if ($status !== $expectedStatus) {
            throw new RuntimeException(sprintf(
                'Expected HTTP %d for %s %s, got %d. Payload: %s',
                $expectedStatus,
                $method,
                $path,
                $status,
                $response
            ));
        }

        return $json;
    }

    /** @param array<int, string> $headers */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        throw new RuntimeException('Response status header was not found.');
    }

    /** @param array<int, string> $headers */
    private function storeCookies(array $headers): void
    {
        foreach ($headers as $header) {
            if (!str_starts_with($header, 'Set-Cookie:')) {
                continue;
            }

            $cookie = trim(substr($header, strlen('Set-Cookie:')));
            $firstPart = explode(';', $cookie, 2)[0];
            [$name, $value] = array_pad(explode('=', $firstPart, 2), 2, '');

            if ($name !== '') {
                $this->cookies[$name] = $value;
            }
        }
    }

    /** @param array<int, string> $headers */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $header) {
            if (!str_contains($header, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $header, 2);
            $normalized[strtolower(trim($name))] = trim($value);
        }

        return $normalized;
    }
}
