<?php

class Crypto
{
    public const VAULT_PREFIX = 'v2:';
    public const BACKUP_VERSION = 2;
    public const WRAPPED_KEY_PREFIX = 'wk1:';

    public static function assertAvailable(): void
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('The sodium extension is required.');
        }
    }

    public static function newSalt(): string
    {
        self::assertAvailable();
        return base64_encode(random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES));
    }

    public static function deriveKey(string $password, string $encodedSalt): string
    {
        self::assertAvailable();
        $salt = base64_decode($encodedSalt, true);
        if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            throw new RuntimeException('Invalid KDF salt.');
        }

        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_DEFAULT
        );
    }

    public static function pepperedSecret(string $secret): string
    {
        if (APP_SECRET === '') {
            throw new RuntimeException('APP_SECRET must not be empty.');
        }
        return hash_hmac('sha256', $secret, APP_SECRET, true);
    }

    public static function passwordHashInput(string $password): string
    {
        return base64_encode(self::pepperedSecret($password));
    }

    public static function hashPassword(string $password): string
    {
        return password_hash(self::passwordHashInput($password), HASH_ALGO);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify(self::passwordHashInput($password), $hash)
            || password_verify($password, $hash);
    }

    public static function deriveMasterKey(string $password, string $encodedSalt): string
    {
        return self::deriveKey(self::passwordHashInput($password), $encodedSalt);
    }

    public static function deriveRecoveryKey(string $recoveryCode, string $encodedSalt): string
    {
        return self::deriveKey(base64_encode(self::pepperedSecret(self::normalizeRecoveryCode($recoveryCode))), $encodedSalt);
    }

    public static function resetTokenKey(string $resetToken): string
    {
        return hash_hmac('sha256', $resetToken, APP_SECRET, true);
    }

    public static function newVaultKey(): string
    {
        self::assertAvailable();
        return random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function wrapVaultKey(string $vaultKey, string $wrappingKey): string
    {
        self::assertKey($vaultKey);
        self::assertKey($wrappingKey);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($vaultKey, $nonce, $wrappingKey);
        return self::WRAPPED_KEY_PREFIX . base64_encode($nonce . $cipher);
    }

    public static function unwrapVaultKey(string $wrappedVaultKey, string $wrappingKey): ?string
    {
        if (!str_starts_with($wrappedVaultKey, self::WRAPPED_KEY_PREFIX)) {
            return null;
        }

        return self::decryptSecretbox(substr($wrappedVaultKey, strlen(self::WRAPPED_KEY_PREFIX)), $wrappingKey);
    }

    public static function encryptVault(string $plaintext, string $key): string
    {
        self::assertKey($key);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return self::VAULT_PREFIX . base64_encode($nonce . $cipher);
    }

    public static function decryptVault(string $encoded, string $key, ?string $legacyKey = null): ?string
    {
        if (str_starts_with($encoded, self::VAULT_PREFIX)) {
            return self::decryptSecretbox(substr($encoded, strlen(self::VAULT_PREFIX)), $key);
        }

        if ($legacyKey === null) {
            return null;
        }

        return self::decryptLegacyAesCbc($encoded, $legacyKey);
    }

    public static function encryptBackupJson(string $json, string $backupPassword): array
    {
        $salt = self::newSalt();
        $key = self::deriveKey($backupPassword, $salt);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($json, $nonce, $key);
        sodium_memzero($key);

        return [
            'version' => self::BACKUP_VERSION,
            'kdf' => 'sodium_crypto_pwhash_interactive',
            'cipher' => 'sodium_crypto_secretbox',
            'kdf_salt' => $salt,
            'payload' => base64_encode($nonce . $cipher),
        ];
    }

    public static function decryptBackupJson(array $payload, string $backupPassword): ?string
    {
        if (($payload['version'] ?? null) !== self::BACKUP_VERSION || empty($payload['kdf_salt']) || empty($payload['payload'])) {
            return null;
        }

        $key = self::deriveKey($backupPassword, (string) $payload['kdf_salt']);
        $plain = self::decryptSecretbox((string) $payload['payload'], $key);
        sodium_memzero($key);
        return $plain;
    }

    public static function legacyKeyFromPasswordHash(string $passwordHash): string
    {
        return hash('sha256', $passwordHash, true);
    }

    public static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function generateRecoveryCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $raw = '';
        for ($i = 0; $i < 16; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4) . '-' . substr($raw, 12, 4);
    }

    private static function decryptSecretbox(string $encoded, string $key): ?string
    {
        self::assertKey($key);
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        return $plain === false ? null : $plain;
    }

    private static function decryptLegacyAesCbc(string $encoded, string $key): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if ($ivLength === false || strlen($raw) <= $ivLength) {
            return null;
        }

        $iv = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);
        $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? null : $plain;
    }

    private static function assertKey(string $key): void
    {
        self::assertAvailable();
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Invalid encryption key length.');
        }
    }
}
