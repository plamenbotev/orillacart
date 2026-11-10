<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Host;

use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;
use Psr\Clock\ClockInterface;

/**
 * `time`: moments as ISO 8601 strings in the shop's timezone, which compare as text. A script keeps no date
 * objects, only these strings.
 */
final readonly class TimeFunctions implements HostNamespace
{
    private const string FORMAT = 'Y-m-d\TH:i:sP';

    public function __construct(private ClockInterface $clock) {}

    public function functions(): array
    {
        // Each only works a value out: none changes the shop or reaches outside it.
        return HostFunction::reading([
            new HostFunction('time.now', fn(Context $c): string => $this->clock->now()->format(self::FORMAT), 0, 0, 'time.now(): this moment, as "2026-11-07T09:30:00+02:00"'),
            new HostFunction('time.today', fn(Context $c): string => $this->clock->now()->format('Y-m-d'), 0, 0, 'time.today(): the day, as "2026-11-07"'),
            new HostFunction('time.parse', fn(Context $c, mixed $text): string => $this->moment($text)->format(self::FORMAT), 1, 1, 'time.parse(text): a date or moment written any usual way, as time.now() writes it'),
            new HostFunction('time.add', fn(Context $c, mixed $moment, mixed $amount): string => $this->moment($moment)->modify(self::relative($amount))->format(self::FORMAT), 2, 2, 'time.add(moment, "30 days"): the moment later by that much ("-2 hours" earlier)'),
            new HostFunction('time.format', fn(Context $c, mixed $moment, mixed $format): string => $this->moment($moment)->format(Ops::text($format)), 2, 2, 'time.format(moment, "d.m.Y"): the moment written with PHP\'s date format'),
            new HostFunction('time.diff', fn(Context $c, mixed $from, mixed $to): int => $this->moment($to)->getTimestamp() - $this->moment($from)->getTimestamp(), 2, 2, 'time.diff(from, to): the seconds between'),
        ]);
    }

    private function moment(mixed $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable(Ops::text($value), $this->clock->now()->getTimezone());
        } catch (\Exception) {
            throw new ScriptError(\sprintf('"%s" is not a date or a moment', Ops::text($value)));
        }
    }

    private static function relative(mixed $amount): string
    {
        $text = Ops::text($amount);
        if (preg_match('/^[-+]?\s*\d+\s+(second|minute|hour|day|week|month|year)s?$/', $text) !== 1) {
            throw new ScriptError(\sprintf('"%s" is not an amount of time such as "30 days" or "-2 hours"', $text));
        }

        return $text;
    }
}
