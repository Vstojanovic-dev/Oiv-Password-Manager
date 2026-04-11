<?php

class Response
{
    /**
     * Send a successful response.
     *
     * @param mixed $data    The payload to return (array, object, or null).
     * @param int   $status  HTTP status code (default 200).
     */
    public static function success(mixed $data = null, int $status = 200): void
    {
        self::send(['success' => true, 'data' => $data], $status);
    }

    /**
     * Send an error response.
     *
     * @param string $message  Human-readable error description.
     * @param int    $status   HTTP status code (default 400).
     */
    public static function error(string $message, int $status = 400): void
    {
        self::send(['success' => false, 'error' => $message], $status);
    }

    /**
     * Encode and emit the response, then exit.
     */
    private static function send(array $body, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}