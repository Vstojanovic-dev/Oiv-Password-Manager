<?php

require_once __DIR__ . '/../../src/middleware/Auth.php';
require_once __DIR__ . '/../../utils/Response.php';

class GeneratorController
{
    public function password(): void
    {
        Auth::requireAuth();
        $body = $this->parseJsonBody();

        $length = isset($body['length']) ? (int) $body['length'] : 20;
        $sets = [
            'uppercase' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'lowercase' => 'abcdefghijkmnopqrstuvwxyz',
            'numbers' => '23456789',
            'symbols' => '!@#$%^&*()-_=+[]{};:,.?/',
        ];

        $enabled = [];
        foreach ($sets as $name => $chars) {
            if (($body[$name] ?? true) === true) {
                $enabled[] = $chars;
            }
        }

        if ($length < 8 || $length > 128) {
            Response::error('length must be between 8 and 128.', 422);
        }
        if (empty($enabled)) {
            Response::error('At least one character group must be enabled.', 422);
        }

        $passwordChars = [];
        foreach ($enabled as $chars) {
            $passwordChars[] = $chars[random_int(0, strlen($chars) - 1)];
        }

        $pool = implode('', $enabled);
        while (count($passwordChars) < $length) {
            $passwordChars[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        for ($i = count($passwordChars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$passwordChars[$i], $passwordChars[$j]] = [$passwordChars[$j], $passwordChars[$i]];
        }

        Response::success(['password' => implode('', $passwordChars)]);
    }

    private function parseJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            Response::error('Request body must be valid JSON.', 400);
        }

        return $data;
    }
}
