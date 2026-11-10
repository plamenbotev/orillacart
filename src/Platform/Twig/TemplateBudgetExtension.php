<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\CoreExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The budget of an extension's templates, which run on the shop's pages: TemplateBudgetVisitor makes their
 * `range` and `..`, `number_format` and `format` these capped ones, and counts every loop turn, macro call and
 * arrow function call here. One render (TwigRenderer's, with whatever it includes and the slots it reaches) has
 * 100 000 turns and 32 MB more memory; past either it stops with TemplateBudgetExceeded. The shop's and the
 * theme's templates are left alone.
 */
final class TemplateBudgetExtension extends AbstractExtension
{
    public const string PREFIX = 'extension_';
    /** How many numbers `range` and `..` make. */
    public const int RANGE = 10000;
    /** Loop turns, macro calls and arrow function calls in one render. */
    public const int TURNS = 100000;
    /** How much more memory one render may hold than it started with. */
    public const int MEMORY = 32 * 1024 * 1024;
    /** The decimals `number_format` takes. */
    public const int DECIMALS = 20;
    /** The widest field or precision `format` takes. */
    public const int WIDTH = 1000;

    private int $depth = 0;
    private int $turns = 0;
    private int $memory;

    public function __construct()
    {
        $this->memory = memory_get_usage();
    }

    public function getFunctions(): array
    {
        return [new TwigFunction(self::PREFIX . 'range', self::range(...))];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter(self::PREFIX . 'number_format', $this->numberFormat(...), ['needs_environment' => true]),
            new TwigFilter(self::PREFIX . 'format', self::format(...)),
        ];
    }

    /** A render begins; the outermost one starts the count afresh. */
    public function enter(): void
    {
        if ($this->depth++ === 0) {
            $this->turns = 0;
            $this->memory = memory_get_usage();
        }
    }

    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    /** One loop turn, macro call or arrow function call; null, so the generated code can put it before a value with `??`. */
    public function tick(): null
    {
        if (++$this->turns > self::TURNS) {
            throw new TemplateBudgetExceeded(\sprintf('The extension\'s template ran more than %d loop turns', self::TURNS));
        }
        if (memory_get_usage() - $this->memory > self::MEMORY) {
            throw new TemplateBudgetExceeded(\sprintf('The extension\'s template took more than %d MB', self::MEMORY / 1024 / 1024));
        }

        return null;
    }

    /**
     * PHP's range, as Twig's, of at most RANGE numbers.
     *
     * @return list<mixed>
     */
    public static function range(mixed $start, mixed $end, int|float $step = 1): array
    {
        if (is_numeric($start) && is_numeric($end) && ((float) $step === 0.0 || abs(((float) $end - (float) $start) / (float) $step) >= self::RANGE)) {
            throw new TemplateBudgetExceeded(\sprintf('A range holds at most %d numbers', self::RANGE));
        }
        if (\is_string($start) && \is_string($end) && !is_numeric($start) && !is_numeric($end)) {
            // Letters: `'a'..'z'`, never long.
            return range($start, $end);
        }
        if (!is_numeric($start) || !is_numeric($end)) {
            throw new TemplateBudgetExceeded('A range is of numbers or letters');
        }

        return range(0 + $start, 0 + $end, $step);
    }

    /** Twig's number_format with at most DECIMALS decimals. */
    public function numberFormat(\Twig\Environment $env, mixed $number, ?int $decimal = null, ?string $decimalPoint = null, ?string $thousandSep = null): string
    {
        if ($decimal !== null && ($decimal < 0 || $decimal > self::DECIMALS)) {
            throw new TemplateBudgetExceeded(\sprintf('number_format takes 0 to %d decimals', self::DECIMALS));
        }

        return $env->getExtension(CoreExtension::class)->formatNumber($number, $decimal, $decimalPoint, $thousandSep);
    }

    /** Twig's format (sprintf) with no field or precision wider than WIDTH. */
    public static function format(mixed $format, mixed ...$values): string
    {
        $format = \is_scalar($format) ? (string) $format : '';
        preg_match_all('/%[^a-zA-Z%]*/', $format, $specs);
        foreach ($specs[0] as $spec) {
            preg_match_all('/\d+/', $spec, $numbers);
            foreach ($numbers[0] as $number) {
                if (\strlen($number) > 4 || (int) $number > self::WIDTH) {
                    throw new TemplateBudgetExceeded(\sprintf('format takes fields of at most %d characters', self::WIDTH));
                }
            }
        }

        return CoreExtension::sprintf($format, ...$values);
    }

    public function getNodeVisitors(): array
    {
        return [new TemplateBudgetVisitor()];
    }
}
