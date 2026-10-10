<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Enum;

/**
 * What a single version does to its live record: a new record, a deletion
 * or a move by its version state, otherwise edited when a field differs
 * from live and identical when none does (a copy TYPO3 made along with an
 * edit elsewhere).
 */
enum ChangeKind: string
{
    case New = 'new';
    case Deleted = 'deleted';
    case Moved = 'moved';
    case Identical = 'identical';
    case Edited = 'edited';
}
