<?php

namespace App\Support;

final class Search
{
    /**
     * Build a LIKE pattern for a user supplied term, escaping the wildcards so
     * they are matched literally.
     */
    public static function pattern(?string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $term).'%';
    }
}
