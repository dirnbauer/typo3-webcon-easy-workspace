<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Enum;

/**
 * What the toolbar dropdown is currently scoped to. News wins over
 * page when both uids are present (a news record is always shown on
 * some page, but the editor opened it via the news module).
 *
 * The backing values are part of the AJAX wire format ("context" in
 * the toolbar JSON consumed by components/wew-toolbar-menu.js).
 */
enum ToolbarContext: string
{
    case None = 'none';
    case Page = 'page';
    case News = 'news';

    public static function resolve(int $pageUid, int $newsUid): self
    {
        return match (true) {
            $newsUid > 0 => self::News,
            $pageUid > 0 => self::Page,
            default => self::None,
        };
    }
}
