<?php

declare(strict_types=1);

final class UserRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByEmail(string $email): ?array
    {
        $sql = "SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $sql = "SELECT * FROM users WHERE id = :id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function touchLastLogin(int $id): void
    {
        $sql = "UPDATE users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id";
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'last_login_at' => $now,
            'updated_at' => $now,
            'id' => $id,
        ]);
    }

    public function createAdmin(string $name, string $email, string $password): int
    {
        $sql = "
            INSERT INTO users (name, email, password_hash, is_active, created_at)
            VALUES (:name, :email, :password_hash, 1, :created_at)
        ";
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'name' => trim($name),
            'email' => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function countAllUsers(): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM users";
            $stmt = $this->pdo->query($sql);
            $count = $stmt !== false ? $stmt->fetchColumn() : 0;
        } catch (Throwable $exception) {
            $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $message = strtolower($exception->getMessage());
            if ($driver === 'sqlite' && strpos($message, 'no such table') !== false) {
                return 0;
            }
            throw $exception;
        }

        return (int) $count;
    }

    public function hasAnyUser(): bool
    {
        return $this->countAllUsers() > 0;
    }
}
