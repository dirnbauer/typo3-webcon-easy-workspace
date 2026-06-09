<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\UserSettings;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Security\BackendAccessGuard;

final class ConfigurationProviderTest extends UnitTestCase
{
    private ConfigurationProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ConfigurationProvider(new BackendAccessGuard(new Context()));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $tsConfigOptions options.webcon_easy_workspace.* values
     * @param array<string, mixed> $userSettings
     */
    private function setUpBackendUserWith(array $tsConfigOptions, array $userSettings = []): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getTSConfig')->willReturn(
            $tsConfigOptions === [] ? [] : ['options.' => ['webcon_easy_workspace.' => $tsConfigOptions]],
        );
        $backendUser->method('getUserSettings')->willReturn(new UserSettings($userSettings));
        $GLOBALS['BE_USER'] = $backendUser;
    }

    #[Test]
    public function defaultsApplyWithoutBackendUser(): void
    {
        $config = $this->subject->get();

        self::assertTrue($config['enabled']);
        self::assertSame('changed', $config['defaultMode']);
        self::assertSame(200, $config['maxItems']);
        self::assertTrue($config['enableRevert']);
    }

    #[Test]
    public function userTsConfigDisablesTheExtension(): void
    {
        $this->setUpBackendUserWith(['enabled' => '0']);

        $config = $this->subject->get();

        self::assertFalse($config['enabled']);
        // The per-user opt-in is independent of the master switch.
        self::assertTrue($config['userEnabled']);
    }

    #[Test]
    public function userSettingOverridesUserEnabledDefault(): void
    {
        $this->setUpBackendUserWith([], ['webconEasyWorkspaceEnabled' => '0']);

        $config = $this->subject->get();

        self::assertFalse($config['enabled']);
        self::assertFalse($config['userEnabled']);
    }

    #[Test]
    public function booleanStringsAreNormalized(): void
    {
        $this->setUpBackendUserWith([
            'enableFilter' => 'true',
            'enableThumbnails' => 'off',
            'enableRevert' => 'yes',
            'showHidden' => '0',
        ]);

        $config = $this->subject->get();

        self::assertTrue($config['enableFilter']);
        self::assertFalse($config['enableThumbnails']);
        self::assertTrue($config['enableRevert']);
        self::assertFalse($config['showHidden']);
    }

    #[Test]
    public function defaultModeIsNormalizedToKnownValues(): void
    {
        $this->setUpBackendUserWith(['defaultMode' => ' ALL ']);
        self::assertSame('all', $this->subject->get()['defaultMode']);

        $this->setUpBackendUserWith(['defaultMode' => 'bogus']);
        self::assertSame('changed', $this->subject->get()['defaultMode']);
    }

    #[Test]
    public function maxItemsIsCoercedToAtLeastOne(): void
    {
        $this->setUpBackendUserWith(['maxItems' => '0']);
        self::assertSame(1, $this->subject->get()['maxItems']);

        $this->setUpBackendUserWith(['maxItems' => '50']);
        self::assertSame(50, $this->subject->get()['maxItems']);
    }
}
