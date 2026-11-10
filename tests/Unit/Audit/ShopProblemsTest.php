<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Audit;

use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Audit\Application\ShopProblems;
use OrillaCart\Audit\Domain\ChangeRecordRepository;
use OrillaCart\Audit\Domain\LoggedProblem;
use OrillaCart\Audit\Domain\LoggedProblemRepository;
use OrillaCart\Audit\Infrastructure\Events\ForgetErasedPeople;
use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use OrillaCart\Shared\Security\LoggedText;
use OrillaCart\Shared\Security\Signatures;
use OrillaCart\Shared\Users\UserNames;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ShopProblemsTest extends TestCase
{
    public function testErasingAPersonRemovesTheProblemsAboutThem(): void
    {
        $signatures = new Signatures('the site key');
        $now = new \DateTimeImmutable('2026-11-10 12:00:00');
        $problems = $this->problems([
            new LoggedProblem('a', 'warning', 'The email order_confirmation to [email] did not go: Jane Doe has no mailbox', 3, $now, $now, (string) LoggedText::aboutMark($signatures, 'jane@example.com')),
            new LoggedProblem('b', 'warning', 'The email order_confirmation to [email] did not go', 1, $now, $now, LoggedText::aboutMark($signatures, 'joe@example.com') . ' ' . LoggedText::aboutMark($signatures, 'ann@example.com')),
            new LoggedProblem('c', 'error', 'An add-on wrote about jane@example.com itself', 1, $now, $now),
            new LoggedProblem('d', 'error', 'The courier did not answer', 9, $now, $now),
        ]);
        $shop = new ShopProblems($problems, new MockClock($now), $signatures);

        self::assertSame(2, $shop->forgetPerson('Jane@Example.com'));
        self::assertSame(['b', 'd'], $problems->fingerprints());

        self::assertSame(1, $shop->forgetPerson('ann@example.com'));
        self::assertSame(['d'], $problems->fingerprints());
        self::assertSame(0, $shop->forgetPerson(''));
    }

    public function testAPrivacyRequestReachesTheProblemsFromAnOrderAndFromAPerson(): void
    {
        $signatures = new Signatures('the site key');
        $now = new \DateTimeImmutable('2026-11-10 12:00:00');
        $problems = $this->problems([
            new LoggedProblem('a', 'warning', 'About the order\'s customer', 1, $now, $now, (string) LoggedText::aboutMark($signatures, 'jane@example.com')),
            new LoggedProblem('b', 'warning', 'About the person', 1, $now, $now, (string) LoggedText::aboutMark($signatures, 'joe@example.com')),
            new LoggedProblem('c', 'warning', 'About nobody', 1, $now, $now),
        ]);
        $records = self::createStub(ChangeRecordRepository::class);
        $listener = new ForgetErasedPeople(new ChangeLog($records, self::createStub(UserNames::class), new MockClock($now)), new ShopProblems($problems, new MockClock($now), $signatures));

        $listener->anonymised(new OrderAnonymised(12, 'jane@example.com'));
        self::assertSame(['b', 'c'], $problems->fingerprints());

        $listener->personErased(new PersonErased('joe@example.com'));
        self::assertSame(['c'], $problems->fingerprints());
    }

    /**
     * @param list<LoggedProblem> $kept
     */
    private function problems(array $kept): KeptProblems
    {
        return new KeptProblems($kept);
    }
}

/** The problems table in memory. */
final class KeptProblems implements LoggedProblemRepository
{
    /** @param list<LoggedProblem> $kept */
    public function __construct(private array $kept) {}

    /** @return list<string> */
    public function fingerprints(): array
    {
        return array_map(static fn(LoggedProblem $problem): string => $problem->fingerprint(), $this->kept);
    }

    public function page(int $offset, int $limit): array
    {
        return \array_slice($this->kept, $offset, $limit);
    }

    public function total(): int
    {
        return \count($this->kept);
    }

    public function seenSince(\DateTimeImmutable $at): int
    {
        return \count($this->kept);
    }

    public function clear(): void
    {
        $this->kept = [];
    }

    public function forgetBefore(\DateTimeImmutable $at): void {}

    public function forgetAbout(string $email, ?string $mark): int
    {
        $before = \count($this->kept);
        $this->kept = array_values(array_filter($this->kept, static fn(LoggedProblem $problem): bool => !(
            ($email !== '' && mb_stripos($problem->message(), $email) !== false) || ($mark !== null && str_contains($problem->about(), $mark))
        )));

        return $before - \count($this->kept);
    }
}
