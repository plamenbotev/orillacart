<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Extension\FailsClosed;
use OrillaCart\Extension\Tax\TaxLine;
use OrillaCart\Extension\Tax\TaxProvider;
use OrillaCart\Extension\Tax\TaxRequest;
use OrillaCart\Extension\Tax\TaxResult;
use OrillaCart\Shared\Extension\ExtensionFailures;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Tax\Domain\TaxGroupRepository;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tax\Domain\TaxSettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * The tax service the shop chose, as checkout asks it. A quote is asked once per request for the same lines. A
 * service that fails, or answers with taxes that cannot be, is logged: the shop's own rates are used and the
 * order notes it, or checkout refuses, as the shop chose.
 */
final class TaxServices
{
    /** @var array<string, TaxResult> quotes already given in this request, by what was asked */
    private array $quoted = [];

    public function __construct(
        private readonly TaxSettingsRepository $settings,
        private readonly InstalledExtensions $extensions,
        private readonly LoggerInterface $logger,
        private readonly ExtensionFailures $failures,
        private readonly TaxGroupRepository $groups,
        private readonly TaxQuoteCache $cache,
    ) {}

    /** How long a service's answer is kept: long enough for one checkout. */
    public const int KEPT_SECONDS = 600;

    /**
     * What tax services call the goods of a tax group, by its id; null for the shop's default group.
     */
    public function codeOf(?int $groupId): ?string
    {
        $groupId ??= $this->settings->load()->defaultGroupId;

        return $groupId === null ? null : $this->groups->find($groupId)?->taxCode();
    }

    /** The chosen service; null for the shop's own rates, or when its add-on is off. */
    public function active(): ?TaxProvider
    {
        return $this->provider($this->settings->load()->provider);
    }

    public function provider(?string $id): ?TaxProvider
    {
        foreach ($id === null ? [] : $this->extensions->taxProviders() as $provider) {
            if ($provider->id() === $id) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * The service's taxes for the request; null when no service is chosen, or it failed and the shop's own
     * rates stand in.
     *
     * @throws TaxServiceUnavailable when it failed and the shop takes no order without it
     */
    public function quote(TaxRequest $request): ?TaxResult
    {
        $provider = $this->active();
        if ($provider === null) {
            return null;
        }
        $key = hash('sha256', $provider->id() . "\0" . serialize($request));
        if (isset($this->quoted[$key])) {
            return $this->quoted[$key];
        }
        $kept = $this->cache->get($key);
        if ($kept !== null) {
            $result = self::fromRows($kept);
            if ($result !== null && self::problem($request, $result) === null) {
                return $this->quoted[$key] = $result;
            }
        }
        try {
            $result = $provider->quote($request);
            $problem = self::problem($request, $result);
        } catch (\Throwable $e) {
            $result = null;
            $problem = $e->getMessage();
            $this->logger->error('The tax service {provider} failed: {message}', ['provider' => $provider->id(), 'message' => $problem, 'exception' => $e]);
        }
        if ($result === null || $problem !== null) {
            if ($result !== null) {
                $this->logger->error('The tax service {provider} answered taxes that cannot be: {problem}', ['provider' => $provider->id(), 'problem' => $problem]);
            }
            $refuse = $this->settings->load()->whenProviderFails === TaxSettings::REFUSE || $provider instanceof FailsClosed;
            if ($refuse) {
                throw new TaxServiceUnavailable();
            }
            $this->failures->record(\sprintf('The tax service %s failed; the order was taxed at the shop\'s own rates.', $provider->id()), false);

            return null;
        }

        $this->cache->put($key, self::rows($result), self::KEPT_SECONDS);

        return $this->quoted[$key] = $result;
    }

    /** @return list<array<string, string|bool|null>> */
    private static function rows(TaxResult $result): array
    {
        $rows = [];
        foreach ($result->lines as $line => $taxes) {
            foreach ($taxes as $tax) {
                $rows[] = ['line' => (string) $line, 'name' => $tax->name, 'percent' => $tax->percent, 'country' => $tax->country, 'region' => $tax->region, 'level' => $tax->level, 'code' => $tax->code, 'jurisdiction' => $tax->jurisdiction, 'taxable' => $tax->taxable, 'amount' => $tax->amount, 'exempt' => $tax->exempt];
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, string|bool|null>> $rows
     *
     * @return TaxResult|null null for rows that are not an answer
     */
    private static function fromRows(array $rows): ?TaxResult
    {
        $lines = [];
        foreach ($rows as $row) {
            $text = static fn(string $name): ?string => \is_string($row[$name] ?? null) ? $row[$name] : null;
            [$line, $name, $percent, $country, $level, $code, $jurisdiction, $taxable, $amount] = array_map($text, ['line', 'name', 'percent', 'country', 'level', 'code', 'jurisdiction', 'taxable', 'amount']);
            if ($line === null || $name === null || $percent === null || $country === null || $level === null || $code === null || $jurisdiction === null || $taxable === null || $amount === null) {
                return null;
            }
            $lines[$line][] = new TaxLine($name, $percent, $country, $text('region'), $level, $code, $jurisdiction, $taxable, $amount, ($row['exempt'] ?? false) === true);
        }

        return new TaxResult($lines);
    }

    /** What is wrong with a service's answer; null when nothing. */
    private static function problem(TaxRequest $request, TaxResult $result): ?string
    {
        $ids = array_map(static fn($line): string => $line->id, $request->lines);
        foreach ($result->lines as $id => $taxes) {
            if (!\in_array($id, $ids, true)) {
                return \sprintf('no line "%s" was asked about', $id);
            }
            foreach ($taxes as $tax) {
                if (!$tax instanceof TaxLine) {
                    return 'a line\'s taxes are not TaxLines';
                }
                foreach (['percent' => $tax->percent, 'taxable' => $tax->taxable, 'amount' => $tax->amount] as $what => $number) {
                    if (preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $number) !== 1) {
                        return \sprintf('%s "%s" is not a number', $what, $number);
                    }
                }
                if (preg_match('/^[A-Z]{2}$/', $tax->country) !== 1 || !\in_array($tax->level, [TaxLine::COUNTRY, TaxLine::REGION, TaxLine::LOCAL], true) || trim($tax->code) === '') {
                    return \sprintf('the jurisdiction of "%s" is not one', $tax->name);
                }
                if (str_starts_with($tax->amount, '-') || ($tax->exempt && (float) $tax->amount !== 0.0)) {
                    return \sprintf('the tax "%s" is below zero, or charged though exempt', $tax->name);
                }
            }
        }

        return null;
    }
}
