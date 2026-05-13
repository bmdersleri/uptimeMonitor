<?php

declare(strict_types=1);

final class AuthService
{
    private const SESSION_USER_ID = 'auth_user_id';

    /** @var UserRepository */
    private $users;

    public function __construct(UserRepository $users)
    {
        $this->users = $users;
    }

    public function attemptLogin(string $email, string $password): bool
    {
        $user = $this->users->findActiveByEmail($email);
        if ($user === null) {
            return false;
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if (!password_verify($password, $hash)) {
            return false;
        }

        $_SESSION[self::SESSION_USER_ID] = (int) $user['id'];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->users->touchLastLogin((int) $user['id']);

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_USER_ID]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if (!isset($_SESSION[self::SESSION_USER_ID])) {
            return null;
        }

        $userId = (int) $_SESSION[self::SESSION_USER_ID];
        if ($userId < 1) {
            return null;
        }

        return $this->users->findById($userId);
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function requireAuth(string $redirectPath = '/login.php'): void
    {
        if ($this->check()) {
            return;
        }

        if (function_exists('url_for') && $redirectPath === '/login.php') {
            $redirectPath = url_for('/login.php');
        }

        header('Location: ' . $redirectPath);
        exit;
    }
}
