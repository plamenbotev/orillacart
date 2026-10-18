<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Doctrine;

use OrillaCart\Platform\Doctrine\AfterCommit;
use PHPUnit\Framework\TestCase;

final class AfterCommitTest extends TestCase
{
    /** @var list<string> */
    private array $ran = [];

    public function testOutsideATransactionTheWorkRunsAtOnce(): void
    {
        $after = new AfterCommit();
        $after->defer($this->note('now'));

        self::assertSame(['now'], $this->ran);
    }

    public function testTheWorkWaitsForTheOutermostCommit(): void
    {
        $after = new AfterCommit();
        $after->begin();
        $after->defer($this->note('outer'));
        $after->begin();
        $after->defer($this->note('savepoint'));
        $after->commit();

        self::assertSame([], $this->ran, 'a savepoint commits nothing yet');

        $after->commit();

        self::assertSame(['outer', 'savepoint'], $this->ran);
    }

    public function testWorkOfARolledBackSavepointIsDroppedAndTheRestStays(): void
    {
        $after = new AfterCommit();
        $after->begin();
        $after->defer($this->note('kept'));
        $after->begin();
        $after->defer($this->note('dropped'));
        $after->rollBack();
        $after->commit();

        self::assertSame(['kept'], $this->ran);
    }

    public function testARolledBackTransactionRunsNothing(): void
    {
        $after = new AfterCommit();
        $after->begin();
        $after->defer($this->note('never'));
        $after->rollBack();

        self::assertSame([], $this->ran);
    }

    public function testOneFailingPieceDoesNotStopTheOthers(): void
    {
        $after = new AfterCommit();
        $after->begin();
        $after->defer(static fn() => throw new \RuntimeException('add-on broke'));
        $after->defer($this->note('still told'));

        try {
            $after->commit();
            self::fail('The failure is passed on.');
        } catch (\RuntimeException $e) {
            self::assertSame('add-on broke', $e->getMessage());
        }
        self::assertSame(['still told'], $this->ran);

        $after->defer($this->note('after'));
        self::assertSame(['still told', 'after'], $this->ran, 'no transaction is left open');
    }

    /**
     * @return \Closure(): void
     */
    private function note(string $what): \Closure
    {
        return function () use ($what): void {
            $this->ran[] = $what;
        };
    }
}
