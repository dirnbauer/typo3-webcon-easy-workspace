<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Enum;

/**
 * The three submodules of Content > Easy Workspace. The backing value is the
 * legacy `?section=` query value and names the Fluid partial
 * (`Partials/Backend/Module/Section/<Value>.html`).
 */
enum ModuleSection: string
{
    case Pending = 'pending';
    case All = 'all';
    case Diagnostics = 'diagnostics';

    /**
     * The parent module (`webcon_easy_workspace`) opens the pending list.
     */
    public static function fromModuleIdentifier(string $identifier): self
    {
        return array_find(
            self::cases(),
            static fn(self $section): bool => $section->moduleIdentifier() === $identifier,
        ) ?? self::Pending;
    }

    public function moduleIdentifier(): string
    {
        return match ($this) {
            self::Pending => 'webcon_easy_workspace_pending',
            self::All => 'webcon_easy_workspace_records',
            self::Diagnostics => 'webcon_easy_workspace_diagnostics',
        };
    }

    public function titleKey(): string
    {
        return match ($this) {
            self::Pending => 'module.section.pending',
            self::All => 'module.section.all',
            self::Diagnostics => 'module.section.testsDiagnostics',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::Pending => 'module.pending.subtitle',
            self::All => 'module.all.subtitle',
            self::Diagnostics => 'module.testsDiagnostics.subtitle',
        };
    }

    public function partialName(): string
    {
        return ucfirst($this->value);
    }

    public function isAdminOnly(): bool
    {
        return $this === self::Diagnostics;
    }
}
