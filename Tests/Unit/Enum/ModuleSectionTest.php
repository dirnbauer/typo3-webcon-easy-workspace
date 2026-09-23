<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Enum\ModuleSection;

final class ModuleSectionTest extends UnitTestCase
{
    /**
     * @return array<string, array{string, ModuleSection}>
     */
    public static function moduleIdentifierDataProvider(): array
    {
        return [
            'parent module opens the pending list' => ['webcon_easy_workspace', ModuleSection::Pending],
            'pending submodule' => ['webcon_easy_workspace_pending', ModuleSection::Pending],
            'records submodule' => ['webcon_easy_workspace_records', ModuleSection::All],
            'diagnostics submodule' => ['webcon_easy_workspace_diagnostics', ModuleSection::Diagnostics],
            'foreign module' => ['web_layout', ModuleSection::Pending],
        ];
    }

    #[Test]
    #[DataProvider('moduleIdentifierDataProvider')]
    public function resolvesTheSectionOfAModule(string $identifier, ModuleSection $expected): void
    {
        self::assertSame($expected, ModuleSection::fromModuleIdentifier($identifier));
    }

    #[Test]
    public function everySectionRoundTripsThroughItsModuleIdentifier(): void
    {
        foreach (ModuleSection::cases() as $section) {
            self::assertSame($section, ModuleSection::fromModuleIdentifier($section->moduleIdentifier()));
        }
    }

    #[Test]
    public function thePartialNameMatchesTheTemplateFile(): void
    {
        foreach (ModuleSection::cases() as $section) {
            self::assertFileExists(
                dirname(__DIR__, 3) . '/Resources/Private/Partials/Backend/Module/Section/' . $section->partialName() . '.html',
            );
        }
    }

    #[Test]
    public function onlyDiagnosticsIsAdminOnly(): void
    {
        self::assertTrue(ModuleSection::Diagnostics->isAdminOnly());
        self::assertFalse(ModuleSection::Pending->isAdminOnly());
        self::assertFalse(ModuleSection::All->isAdminOnly());
    }
}
