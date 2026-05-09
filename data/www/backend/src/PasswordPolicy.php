<?php

class PasswordPolicy
{
    private const BLOCKED_PASSWORDS = [
        '123',
        '1234',
        '12345',
        '123456',
        '1234567',
        'password',
        'admin',
        'qwerty',
        'abc123',
        '111111',
        '000000',
        '123123',
        'password1',
        'password123',
        'password123!',
        'password1234',
        'password@123',
        'password@1',
        'passw0rd',
        'passw0rd123',
        'p@ssw0rd',
        'p@ssw0rd123',
        'password1!',
        '12345678',
        '123456789',
        '1234567890',
        '12345678910',
        '123456789a',
        '123456a',
        '1234qwer',
        '123qwe',
        '1q2w3e4r',
        '1q2w3e4r5t',
        'qwertyuiop',
        'qwerty123',
        'qwerty12345',
        'admin123',
        'admin1234',
        'admin@123',
        'adminadmin',
        'administrator',
        'letmein',
        'letmein123',
        'welcome',
        'welcome123',
        'welcome1',
        'iloveyou',
        'iloveyou1',
        'monkey',
        'monkey123',
        'dragon',
        'dragon123',
        'football',
        'football1',
        'baseball',
        'baseball1',
        'superman',
        'superman1',
        'minecraft',
        'minecraft1',
        'minecraft123',
        'sunshine',
        'sunshine1',
        'princess',
        'princess1',
        'charlie',
        'charlie1',
        'jordan23',
        'zaq12wsx',
        'asdfghjkl',
        'zxcvbnm',
        'zxcvbnm123',
        'trustno1',
        'starwars',
        'starwars1',
        'login123',
        'user1234',
        'pass123',
        'pass1234',
        'pass@123',
        'india@123',
        'aa123456',
        '11111111',
        '22222222',
        '66666666',
        '88888888',
        '00000000',
        'p@ssword',
        'p@ssword123',
        'masterpassword',
        'masterpassword123',
        'changeme',
        'changeme123',
        'default',
        'default123',
    ];

    public static function evaluateMaster(string $password, ?string $currentPassword = null, ?string $username = null): array
    {
        $errors = [];
        $warnings = [];
        $normalizedPassword = self::normalize($password);

        if ($currentPassword !== null && hash_equals($currentPassword, $password)) {
            $errors[] = 'New password must be different from the current password.';
        }

        if ($username !== null && self::normalize($username) !== '' && hash_equals(self::normalize($username), $normalizedPassword)) {
            $errors[] = 'Master password must be different from the username.';
        }

        if (strlen($password) < 10) {
            $errors[] = 'Master password must be at least 10 characters.';
        }

        if (in_array($normalizedPassword, self::BLOCKED_PASSWORDS, true)) {
            $errors[] = 'This master password is too common and cannot be used.';
        }

        $hasUppercase = preg_match('/[A-Z]/', $password) === 1;
        $hasNumber = preg_match('/[0-9]/', $password) === 1;
        $hasSymbol = preg_match('/[^A-Za-z0-9]/', $password) === 1;
        $isStrong = strlen($password) >= 12 && $hasUppercase && $hasNumber && $hasSymbol;

        if (!$isStrong && empty($errors)) {
            $warnings[] = 'Master password is allowed but weak. Use at least 12 characters with uppercase letters, numbers, and symbols.';
            if (!$hasUppercase) {
                $warnings[] = 'Add at least one uppercase letter.';
            }
            if (!$hasNumber) {
                $warnings[] = 'Add at least one number.';
            }
            if (!$hasSymbol) {
                $warnings[] = 'Add at least one symbol.';
            }
        }

        return [
            'allowed' => empty($errors),
            'strength' => $isStrong ? 'strong' : 'weak',
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public static function evaluateVault(string $password, bool $isReused = false): array
    {
        $warnings = [];
        $hasUppercase = preg_match('/[A-Z]/', $password) === 1;
        $hasLowercase = preg_match('/[a-z]/', $password) === 1;
        $hasNumber = preg_match('/[0-9]/', $password) === 1;
        $hasSymbol = preg_match('/[^A-Za-z0-9]/', $password) === 1;
        $isStrong = strlen($password) >= 12 && $hasUppercase && $hasLowercase && $hasNumber && $hasSymbol;

        if (strlen($password) < 10 || !$isStrong) {
            $warnings[] = 'Vault password looks weak. Prefer 12+ characters with uppercase, lowercase, numbers, and symbols.';
        }

        if (in_array(self::normalize($password), self::BLOCKED_PASSWORDS, true)) {
            $warnings[] = 'Vault password is a commonly used password.';
        }

        if ($isReused) {
            $warnings[] = 'This vault password is already used on another saved account.';
        }

        return [
            'strength' => $isStrong ? 'strong' : 'weak',
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private static function normalize(string $password): string
    {
        return strtolower(trim($password));
    }
}
