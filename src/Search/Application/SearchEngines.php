<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Jobs\Application\JobWorkers;
use OrillaCart\Search\Application\Dto\ChangeSearchEngine;
use OrillaCart\Search\Application\Dto\SearchEngineView;
use OrillaCart\Search\Domain\EngineSettings;
use OrillaCart\Search\Domain\EngineSettingsRepository;
use OrillaCart\Search\Domain\SearchEngine;

/**
 * The search engine as the settings screen runs it. Switching to OpenSearch first reaches it and prepares its
 * index; the shop then builds every document there and keeps searching its database until that is done.
 */
final readonly class SearchEngines
{
    public function __construct(
        private EngineSettingsRepository $settings,
        private SearchServer $server,
        private SearchIndexing $indexing,
        private JobWorkers $workers,
    ) {}

    public function current(): SearchEngineView
    {
        return $this->view($this->settings->load());
    }

    public function change(ChangeSearchEngine $command): SearchEngineView
    {
        $saved = $this->settings->load();
        if ($command->engine === SearchEngine::Database->value) {
            $settings = new EngineSettings(SearchEngine::Database, trim($command->host), trim($command->username), $command->clearPassword ? '' : ($command->password === '' ? $saved->password : $command->password), trim($command->index));
            $this->settings->save($settings);

            return $this->view($settings, true, 'The shop searches its database.');
        }

        $wrongHost = self::hostProblem(trim($command->host));
        if ($wrongHost !== null) {
            return $this->view($saved, false, $wrongHost);
        }
        $candidate = new EngineSettings(SearchEngine::OpenSearch, trim($command->host), trim($command->username), $command->clearPassword ? '' : ($command->password === '' ? $saved->password : $command->password), trim($command->index));
        $problem = $this->server->prepare($candidate);
        if ($problem !== null) {
            return $this->view($saved, false, $problem);
        }
        $this->settings->save($candidate);
        $queued = $this->indexing->queueEverything();

        return $this->view($candidate, true, \sprintf('OpenSearch answered. %d products are being indexed there; the shop searches its database until they are.', $queued));
    }

    /**
     * Brings OpenSearch's index up to this version's fields, as after an update: documents with fields its index
     * does not know are refused.
     *
     * @return string|null what went wrong; null when ready, or when the shop searches its database
     */
    public function prepareIndex(): ?string
    {
        $settings = $this->settings->load();

        return $settings->engine === SearchEngine::OpenSearch ? $this->server->prepare($settings) : null;
    }

    /** OpenSearch's index is given this version's fields first: a rebuild is how a failed upgrade is mended. */
    public function rebuild(): SearchEngineView
    {
        $problem = $this->prepareIndex();
        if ($problem !== null) {
            return $this->view($this->settings->load(), false, 'OpenSearch\'s index could not be prepared, so nothing was queued: ' . $problem);
        }
        $queued = $this->indexing->queueEverything();

        return $this->view($this->settings->load(), true, \sprintf('%d products are being indexed again.', $queued));
    }

    private function view(EngineSettings $settings, ?bool $works = null, string $message = ''): SearchEngineView
    {
        $status = $this->indexing->status();

        return new SearchEngineView(
            $settings->engine->value,
            $settings->host,
            $settings->username,
            $settings->password !== '',
            $settings->index,
            $settings->ready,
            $status->due,
            $status->later,
            $status->parked,
            $status->nextAt?->format(\DATE_ATOM),
            $works,
            $message,
            $this->workers->serving(IndexProducts::queue()),
        );
    }

    /** What is wrong with an OpenSearch address, for the screen; null for a good one. */
    private static function hostProblem(string $host): ?string
    {
        $parts = parse_url($host);
        if ($parts === false || !\in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return 'Enter the address OpenSearch listens on, such as https://search.example.com:9200.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Put the user name and password in their own fields, not in the address: it is shown and logged.';
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'The address has no query: just the scheme, the host, the port and a path if any.';
        }

        return null;
    }
}
