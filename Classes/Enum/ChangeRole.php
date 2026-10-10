<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Enum;

/**
 * What a row of the Workspaces module's list is to an editor.
 *
 * A change is something someone edited: it counts, it can be selected and
 * published. A copy is a version TYPO3 made that carries nothing of its own
 * (every node identical to live, a no-op save or a translation left behind):
 * it is offered for cleanup, never counted. Translation copies of a change
 * are neither — they fold into the change as its companions.
 */
enum ChangeRole: string
{
    case Change = 'change';
    case Copy = 'copy';
}
