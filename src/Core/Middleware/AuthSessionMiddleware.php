<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Database;
use App\Core\Request;
use PDO;

/**
 * Middleware that extracts HTTP-only session cookie and attaches authenticated user context to Request.
 */
class AuthSessionMiddleware
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    public function __invoke(Request $request): void
    {
        // Default to null / anonymous guest
        $request->setUser(null);

        $sessionCookie = $_COOKIE['smartsplit_session'] ?? null;
        if (!$sessionCookie || !is_string($sessionCookie) || trim($sessionCookie) === '') {
            return;
        }

        $tokenHash = hash('sha256', trim($sessionCookie));

        $stmt = $this->pdo->prepare("
            SELECT u.`id`, u.`email`, u.`display_name`, u.`avatar_emoji`, u.`avatar_color`, u.`upi_id`, s.`id` AS `session_id`, s.`last_active_at`
            FROM `user_sessions` s
            JOIN `users` u ON s.`user_id` = u.`id`
            WHERE s.`session_token_hash` = :token_hash
              AND s.`expires_at` > NOW()
              AND u.`is_active` = 1
            LIMIT 1
        ");
        $stmt->execute([':token_hash' => $tokenHash]);
        $user = $stmt->fetch();

        if ($user) {
            $request->setUser([
                'id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'display_name' => (string) $user['display_name'],
                'avatar_emoji' => (string) $user['avatar_emoji'],
                'avatar_color' => (string) $user['avatar_color'],
                'upi_id' => isset($user['upi_id']) && $user['upi_id'] !== null ? (string) $user['upi_id'] : null,
                'session_id' => (int) $user['session_id'],
            ]);

            // Throttled session heartbeat: update at most once every 5 minutes (300s)
            $lastActive = !empty($user['last_active_at']) ? strtotime((string) $user['last_active_at']) : 0;
            if (time() - $lastActive > 300) {
                $touchStmt = $this->pdo->prepare("
                    UPDATE `user_sessions` 
                    SET `last_active_at` = NOW() 
                    WHERE `id` = :id
                ");
                $touchStmt->execute([':id' => $user['session_id']]);
            }
        }
    }
}
