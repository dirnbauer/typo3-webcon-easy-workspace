<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The lab's page 2228 in small: an English page with German, Chinese and
 * Hungarian translations, a hero element translated into all three (each
 * with its image), a second and a third element, and a collection element
 * with a translated item — in a site with these four languages.
 *
 * The versions are made the way an editor makes them: by DataHandler in
 * workspace 1, so they are what core really writes (the translation copies
 * included), not what a fixture assumes.
 *
 * Live uids: page 2 (translations 3, 4, 5), hero 100 (101 de, 102 zh,
 * 103 hu) with image references 200 (201, 202, 203), elements 110 (111-113)
 * and 120 (121), collection 130 (131-133) with items 300 and 310 (301-303,
 * 311-313).
 */
trait TranslationCompanionScenario
{
    protected function setUpCompanionScenario(int $userUid = 1): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/TranslationCompanionScenario.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        // Core lists a file reference only when the editor may write its
        // file, which has to exist for that.
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');
        file_put_contents(Environment::getPublicPath() . '/fileadmin/hero.jpg', 'jpeg');
        $this->writeCompanionSite();
        $backendUser = $this->setUpBackendUser($userUid);
        $backendUser->setWorkspace(1);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
    }

    protected function writeCompanionSite(): void
    {
        $path = Environment::getConfigPath() . '/sites/companions';
        GeneralUtility::mkdir_deep($path);
        file_put_contents($path . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'https://companions.test/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'German', 'locale' => 'de_AT.UTF-8', 'base' => '/de/', 'flag' => 'de'],
                ['languageId' => 2, 'title' => 'Chinese', 'locale' => 'zh_CN.UTF-8', 'base' => '/zh/', 'flag' => 'cn'],
                ['languageId' => 3, 'title' => 'Hungarian', 'locale' => 'hu_HU.UTF-8', 'base' => '/hu/', 'flag' => 'hu'],
            ],
        ]));
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
    }

    /**
     * An editor's save or command in the current workspace.
     *
     * @param array<string, array<int|string, array<string, mixed>>> $data
     * @param array<string, array<int, array<string, mixed>>> $commands
     */
    protected function editInWorkspace(array $data, array $commands = []): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands);
        if ($data !== []) {
            $dataHandler->process_datamap();
        }
        if ($commands !== []) {
            $dataHandler->process_cmdmap();
        }
        self::assertSame([], $dataHandler->errorLog, implode(' / ', $dataHandler->errorLog));
    }

    /**
     * The workspace version of a live record (0 for none).
     */
    protected function versionOf(string $table, int $liveUid, int $workspaceId = 1): int
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return Value::int($queryBuilder->select('uid')->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $liveUid),
                $queryBuilder->expr()->eq('t3ver_wsid', $workspaceId),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()->fetchOne());
    }

    /**
     * Every row of the workspace, by table:uid.
     *
     * @return list<string>
     */
    protected function workspaceRows(int $workspaceId = 1): array
    {
        $rows = [];
        foreach (['pages', 'tt_content', 'sys_file_reference', 'tx_easyws_item'] as $table) {
            $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            foreach ($queryBuilder->select('uid')->from($table)
                ->where($queryBuilder->expr()->eq('t3ver_wsid', $workspaceId), $queryBuilder->expr()->eq('deleted', 0))
                ->executeQuery()->fetchFirstColumn() as $uid) {
                $rows[] = $table . ':' . Value::int($uid);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function liveRow(string $table, int $uid): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return Value::stringKeyArray($queryBuilder->select('*')->from($table)
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()->fetchAssociative());
    }
}
