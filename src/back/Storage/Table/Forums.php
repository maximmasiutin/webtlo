<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Table;

use KeepersTeam\Webtlo\Data\Forum;
use KeepersTeam\Webtlo\DB;

final class Forums
{
    /** @var Forum[] */
    private static array $forums = [];

    public function __construct(private readonly DB $db) {}

    /**
     * Получить параметры заданного подраздела.
     */
    public function getForum(int $forumId): ?Forum
    {
        $forum = self::$forums[$forumId] ?? null;

        if ($forum === null) {
            $sql = '
                SELECT f.id, f.name, f.quantity, f.size
                FROM Forums f
                WHERE f.id = :forum_id
            ';

            $res = $this->db->queryRow($sql, ['forum_id' => $forumId]);

            if ($res === null || !count($res)) {
                return null;
            }

            $forum = new Forum(
                id   : (int) $res['id'],
                name : (string) $res['name'],
                count: (int) $res['quantity'],
                size : (int) $res['size'],
            );

            self::$forums[$forumId] = $forum;
        }

        return $forum;
    }

    /**
     * Получить имя заданного подраздела.
     */
    public function getForumName(?int $forumId): string
    {
        if ($forumId === null) {
            return '';
        }

        $forum = self::getForum(forumId: $forumId);

        return $forum->name ?? '';
    }
}
