<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Security\BackendAccessGuard;

final class BackendAccessGuardTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function userReturnsNullWithoutAuthenticatedBackendUser(): void
    {
        $subject = new BackendAccessGuard(new Context());

        self::assertNull($subject->user());
        self::assertNull($subject->user(new ServerRequest()));
    }

    #[Test]
    public function userPrefersRequestAttributeOverGlobal(): void
    {
        $globalUser = $this->createStub(BackendUserAuthentication::class);
        $requestUser = $this->createStub(BackendUserAuthentication::class);
        $GLOBALS['BE_USER'] = $globalUser;
        $request = (new ServerRequest())->withAttribute('backend.user', $requestUser);

        $subject = new BackendAccessGuard(new Context());

        self::assertSame($requestUser, $subject->user($request));
        self::assertSame($globalUser, $subject->user());
    }

    #[Test]
    public function userFallsBackToGlobalWhenRequestCarriesNoUser(): void
    {
        $globalUser = $this->createStub(BackendUserAuthentication::class);
        $GLOBALS['BE_USER'] = $globalUser;

        $subject = new BackendAccessGuard(new Context());

        self::assertSame($globalUser, $subject->user(new ServerRequest()));
    }

    #[Test]
    public function activeWorkspaceIdIsZeroWithoutUserOrInLiveWorkspace(): void
    {
        $subject = new BackendAccessGuard(new Context());
        self::assertSame(0, $subject->activeWorkspaceId());

        $user = $this->createStub(BackendUserAuthentication::class);
        $user->workspace = 0;
        $GLOBALS['BE_USER'] = $user;

        self::assertSame(0, $subject->activeWorkspaceId());
    }

    #[Test]
    public function activeWorkspaceIdUsesUserWorkspaceWhenContextIsLive(): void
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->workspace = 2;
        $GLOBALS['BE_USER'] = $user;

        $subject = new BackendAccessGuard(new Context());

        self::assertSame(2, $subject->activeWorkspaceId());
    }

    #[Test]
    public function activeWorkspaceIdPrefersContextAspectWorkspace(): void
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->workspace = 2;
        $GLOBALS['BE_USER'] = $user;
        $context = new Context();
        $context->setAspect('workspace', new WorkspaceAspect(3));

        $subject = new BackendAccessGuard($context);

        self::assertSame(3, $subject->activeWorkspaceId());
    }

    #[Test]
    public function canModifyTableRequiresUserAndTableName(): void
    {
        $subject = new BackendAccessGuard(new Context());
        self::assertFalse($subject->canModifyTable('tt_content'));

        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('check')->willReturnMap([['tables_modify', 'tt_content', true]]);
        $GLOBALS['BE_USER'] = $user;

        self::assertFalse($subject->canModifyTable(''));
        self::assertTrue($subject->canModifyTable('tt_content'));
    }

    #[Test]
    public function canModifyTableReturnsFalseWhenPermissionIsMissing(): void
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $user;

        self::assertFalse((new BackendAccessGuard(new Context()))->canModifyTable('tt_content'));
    }

    #[Test]
    public function canReadPagePassesWithoutPageScopeButFailsWithoutUser(): void
    {
        $subject = new BackendAccessGuard(new Context());
        self::assertFalse($subject->canReadPage(0));

        $GLOBALS['BE_USER'] = $this->createStub(BackendUserAuthentication::class);

        self::assertTrue($subject->canReadPage(0));
        self::assertTrue($subject->canReadPage(-1));
    }

    #[Test]
    public function hasWorkspaceAccessChecksMembershipForNonAdmins(): void
    {
        $subject = new BackendAccessGuard(new Context());
        self::assertFalse($subject->hasWorkspaceAccess(1));

        $member = $this->createStub(BackendUserAuthentication::class);
        $member->method('isAdmin')->willReturn(false);
        $member->method('checkWorkspace')->willReturnMap([[1, ['uid' => 1, '_ACCESS' => 'member']]]);
        $GLOBALS['BE_USER'] = $member;

        self::assertTrue($subject->hasWorkspaceAccess(1));
        self::assertFalse($subject->hasWorkspaceAccess(0));
    }

    #[Test]
    public function hasWorkspaceAccessDeniesNonMembers(): void
    {
        $outsider = $this->createStub(BackendUserAuthentication::class);
        $outsider->method('isAdmin')->willReturn(false);
        $outsider->method('checkWorkspace')->willReturn(false);
        $GLOBALS['BE_USER'] = $outsider;

        self::assertFalse((new BackendAccessGuard(new Context()))->hasWorkspaceAccess(1));
    }
}
