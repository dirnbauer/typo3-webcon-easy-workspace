<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Hooks;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Keeps a batch discard from failing on TYPO3 v14's dependency resolution.
 *
 * When one DataHandler run discards records together with records that
 * depend on them, the workspaces CommandMap rebuilds the batch into
 * `['version' => …]` commands and copies only an `action` key into them. The
 * current `['discard' => true]` form has no action, so the rebuilt commands
 * arrive empty. Core's DataHandler ignores an empty version command, but the
 * workspaces DataHandlerHook reads `$value['action']` first, and the
 * Development context turns that warning into an exception: the Workspaces
 * module's "discard selected" fails there. Production only logs it.
 *
 * Registered after the workspaces hook (this extension loads after it), so it
 * sees the rebuilt map and drops the empty commands. The records' own
 * `discard` commands stay, so the result is what Production does today.
 * Once Core copies the discard into the rebuilt map, this hook can go:
 * BatchDiscardTest's core test will fail then.
 */
final class DiscardDependencyCommandHook
{
    public function processCmdmap_beforeStart(DataHandler $dataHandler): void
    {
        $dataHandler->cmdmap = self::withoutEmptyVersionCommands($dataHandler->cmdmap);
    }

    /**
     * @param array<mixed> $commandMap
     * @return array<mixed>
     */
    public static function withoutEmptyVersionCommands(array $commandMap): array
    {
        foreach ($commandMap as $table => $records) {
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $id => $commands) {
                if (!is_array($commands) || !array_key_exists('version', $commands)) {
                    continue;
                }
                $version = $commands['version'];
                if (is_array($version) && isset($version['action'])) {
                    continue;
                }
                unset($records[$id]['version']);
                if ($records[$id] === []) {
                    unset($records[$id]);
                }
            }
            if ($records === []) {
                unset($commandMap[$table]);
            } else {
                $commandMap[$table] = $records;
            }
        }

        return $commandMap;
    }
}
