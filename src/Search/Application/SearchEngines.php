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
            $settings = new EngineSettings(SearchEngine::Database, trim($command->host), trim($command->username), $command->password === '' ? $saved->password : $command->password, trim($command->index));
            $this->settings->save($settings);

            return $this->view($settings, true, 'The shop searches its database.');
        }

        $candidate = new EngineSettings(SearchEngine::OpenSearch, trim($command->host), trim($command->username), $command->password === '' ? $saved->password : $command->password, trim($command->index));
        $problem = $this->server->prepare($candidate);
        if ($problem !== null) {
            return $this->view($saved, false, $problem);
        }
        $this->settings->save($candidate);
        $queued = $this->indexing->queueEverything();

        return $this->view($candidate, true, \sprintf('OpenSearch answered. %d products are being indexed there; the shop searches its database until they are.', $queued));
    }

    public function rebuild(): SearchEngineView
    {
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
}
