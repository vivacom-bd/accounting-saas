<?php

declare(strict_types=1);

namespace App\Queries\Admin\Support;

use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/** Announcements as staff see them (all of them, newest start first, at most 200) and the shape both read and write answer with. */
final class Announcements
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = AdminTransaction::connection()->table('announcements')->orderByDesc('starts_at')->orderBy('id')->limit(200)->get();

        return array_values($rows->map(fn (object $row): array => self::present(self::view((array) $row)))->all());
    }

    /** @return array<string, mixed>|null the raw row */
    public function find(string $id): ?array
    {
        $row = AdminTransaction::connection()->table('announcements')->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @param  array<string, mixed>  $row  the raw row
     * @return array<string, mixed>
     */
    public static function view(array $row): array
    {
        $audience = json_decode((string) $row['audience'], true);

        return [
            'id' => $row['id'], 'title' => $row['title'], 'body' => $row['body'], 'kind' => $row['kind'],
            'audience' => is_array($audience) ? $audience : [],
            'starts_at' => self::time($row['starts_at']), 'ends_at' => $row['ends_at'] === null ? null : self::time($row['ends_at']),
            'created_by' => $row['created_by'], 'created_at' => self::time($row['created_at']), 'updated_at' => self::time($row['updated_at']),
        ];
    }

    /**
     * The JSON answer: an empty audience is an object (`{}`), not a list. (The audit keeps plain arrays: JCS has no objects.)
     *
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    public static function present(array $view): array
    {
        $view['audience'] = $view['audience'] === [] ? new stdClass : $view['audience'];

        return $view;
    }

    private static function time(mixed $value): string
    {
        return (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
