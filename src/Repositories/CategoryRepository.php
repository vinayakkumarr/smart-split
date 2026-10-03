<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use InvalidArgumentException;

/**
 * Repository for Expense Categories Taxonomy (System Standards & Workspace Custom Categories).
 */
class CategoryRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Get all active categories (system standard + workspace custom categories).
     *
     * @param int|null $groupId
     * @return array<array{id: int, group_id: int|null, slug: string, name: string, icon: string, color_hex: string, is_system: bool}>
     */
    public function getAll(?int $groupId = null): array
    {
        if ($groupId !== null) {
            $stmt = $this->pdo->prepare("
                SELECT `id`, `group_id`, `slug`, `name`, `icon`, `color_hex`, `is_system`, `created_at`
                FROM `categories`
                WHERE `group_id` IS NULL OR `group_id` = :group_id
                ORDER BY `is_system` DESC, `id` ASC
            ");
            $stmt->execute([':group_id' => $groupId]);
        } else {
            $stmt = $this->pdo->query("
                SELECT `id`, `group_id`, `slug`, `name`, `icon`, `color_hex`, `is_system`, `created_at`
                FROM `categories`
                WHERE `group_id` IS NULL
                ORDER BY `id` ASC
            ");
        }

        return array_map(function (array $c) {
            return [
                'id' => (int) $c['id'],
                'group_id' => $c['group_id'] !== null ? (int) $c['group_id'] : null,
                'slug' => (string) $c['slug'],
                'name' => (string) $c['name'],
                'icon' => (string) $c['icon'],
                'color_hex' => (string) $c['color_hex'],
                'is_system' => (bool) $c['is_system'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Create a workspace-specific custom category.
     *
     * @param int $groupId
     * @param string $name
     * @param string $icon Emoji or icon symbol
     * @param string $colorHex Hex color code e.g. #0284c7
     * @return int Created category ID
     */
    public function createCustom(int $groupId, string $name, string $icon, string $colorHex = '#475569'): int
    {
        $cleanName = trim(strip_tags($name));
        if ($cleanName === '') {
            throw new InvalidArgumentException("Category name cannot be empty.", 422);
        }

        $cleanIcon = trim($icon) !== '' ? trim($icon) : '🏷️';
        $cleanColor = preg_match('/^#[0-9a-fA-F]{6}$/', $colorHex) ? $colorHex : '#475569';

        // Generate sanitized slug
        $baseSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $cleanName));
        $baseSlug = trim($baseSlug, '_');
        if ($baseSlug === '') {
            $baseSlug = 'custom';
        }
        $slug = $baseSlug . '_' . substr(bin2hex(random_bytes(3)), 0, 5);

        $stmt = $this->pdo->prepare("
            INSERT INTO `categories` (`group_id`, `slug`, `name`, `icon`, `color_hex`, `is_system`, `created_at`)
            VALUES (:group_id, :slug, :name, :icon, :color_hex, 0, NOW())
        ");

        $stmt->execute([
            ':group_id' => $groupId,
            ':slug' => $slug,
            ':name' => $cleanName,
            ':icon' => $cleanIcon,
            ':color_hex' => $cleanColor,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Delete a workspace custom category. System categories cannot be deleted.
     *
     * @param int $categoryId
     * @param int $groupId
     * @return bool
     */
    public function deleteCustom(int $categoryId, int $groupId): bool
    {
        // First check if it's a system category
        $cat = $this->findById($categoryId);
        if (!$cat) {
            return false;
        }

        if ($cat['is_system']) {
            throw new InvalidArgumentException("System standard categories cannot be deleted.", 403);
        }

        $stmt = $this->pdo->prepare("
            DELETE FROM `categories`
            WHERE `id` = :id AND `group_id` = :group_id AND `is_system` = 0
        ");
        $stmt->execute([
            ':id' => $categoryId,
            ':group_id' => $groupId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Find category by ID.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT `id`, `group_id`, `slug`, `name`, `icon`, `color_hex`, `is_system`, `created_at`
            FROM `categories`
            WHERE `id` = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'group_id' => $row['group_id'] !== null ? (int) $row['group_id'] : null,
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'icon' => (string) $row['icon'],
            'color_hex' => (string) $row['color_hex'],
            'is_system' => (bool) $row['is_system'],
        ];
    }
}
