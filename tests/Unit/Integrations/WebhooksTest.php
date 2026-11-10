<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Integrations;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Integrations\Application\DeliverWebhook;
use OrillaCart\Integrations\Application\Dto\SaveWebhook;
use OrillaCart\Integrations\Application\Dto\WebhookResponse;
use OrillaCart\Integrations\Application\ViewNormalizer;
use OrillaCart\Integrations\Application\WebhookPayloads;
use OrillaCart\Integrations\Application\Webhooks;
use OrillaCart\Integrations\Application\WebhookSender;
use OrillaCart\Integrations\Application\WebhookTopics;
use OrillaCart\Integrations\Domain\IntegrationRuleViolation;
use OrillaCart\Integrations\Domain\Webhook;
use OrillaCart\Integrations\Domain\WebhookDelivery;
use OrillaCart\Integrations\Domain\WebhookRepository;
use OrillaCart\Integrations\Infrastructure\Events\AnnounceToWebhooks;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Domain\Event\OrderPaid;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Jobs\TestJobs;
use OrillaCart\Tests\Fixtures\Shared\FixedShopManagers;
use OrillaCart\Tests\Fixtures\Shared\FixedUserNames;
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    private TestShop $shop;
    private TestJobs $jobs;
    private InMemoryWebhooks $repository;
    private RecordingSender $sender;
    private Webhooks $webhooks;
    private FixedShopManagers $managers;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->jobs = new TestJobs($this->shop->clock);
        $this->repository = new InMemoryWebhooks();
        $this->sender = new RecordingSender();
        $topics = new class implements WebhookTopics {
            public function all(): array
            {
                return [self::ORDER_PLACED => 'Order placed', self::PRODUCT_CHANGED => 'Product changed', 'loyalty.points' => 'Points earned'];
            }
        };
        $normalizer = new class implements ViewNormalizer {
            public function normalize(object $view): array
            {
                $data = json_decode((string) json_encode($view), true);

                return \is_array($data) ? $data : [];
            }
        };
        $this->managers = new FixedShopManagers([7, 8]);
        $this->jobs->with(new DeliverWebhook($this->repository, new WebhookPayloads($this->shop->orders, $this->shop->products, $normalizer), $this->sender, $this->shop->clock, $this->managers, $this->shop->logger));
        $this->webhooks = new Webhooks($this->repository, $topics, $this->jobs->jobs, $this->shop->clock, new FixedUserNames([7 => 'Mara', 8 => 'Ivo']), $this->managers, $this->shop->logger);
    }

    public function testAnEventGoesToTheWebhooksThatListenSignedWithTheirSecret(): void
    {
        $hook = $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED]), 7);
        $this->webhooks->create(new SaveWebhook('Orders only', 'https://other.example.com/hook', [WebhookTopics::ORDER_PLACED]), 7);

        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->jobs->runAll();

        self::assertCount(1, $this->sender->sent);
        [$url, $body, $headers] = $this->sender->sent[0];
        self::assertSame('https://erp.example.com/hook', $url);
        $event = json_decode($body, true);
        self::assertIsArray($event);
        self::assertSame(WebhookTopics::PRODUCT_CHANGED, $event['topic']);
        self::assertSame('8', self::at($event, 'data', 'product', 'price'), 'the product as the API shows it');
        self::assertSame($event['id'], $headers['X-OrillaCart-Event']);
        self::assertSame(1, preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $headers['X-OrillaCart-Signature'], $signature));
        self::assertSame(hash_hmac('sha256', $signature[1] . '.' . $body, (string) $hook->secret), $signature[2], 'the receiver checks it with the secret');
        self::assertNull($this->webhooks->all()[0]->secret, 'shown once, when made');
    }

    public function testASecretThatCannotBeReadSendsNothingUnsigned(): void
    {
        $hook = $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED]), 7);
        $this->repository->find($hook->id)?->newSecret('');

        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->jobs->runAll();

        self::assertSame([], $this->sender->sent);
        self::assertNotNull($this->webhooks->newSecret($hook->id)->secret, 'a new one is shown');
    }

    public function testAFailedDeliveryIsTriedAgainAndEachTryKept(): void
    {
        $hook = $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', ['loyalty.points']), 7);
        $this->sender->answers = [new WebhookResponse(503, 'Busy', 12), new WebhookResponse(200, '', 8)];

        $this->webhooks->announce('loyalty.points', ['customer' => 7, 'points' => 50]);
        $this->jobs->runAll();
        self::assertSame([[1, 503, false]], array_map(static fn($delivery): array => [$delivery->attempt, $delivery->status, $delivery->succeeded], $this->webhooks->deliveries($hook->id)));

        $this->shop->clock->sleep(61);
        $this->jobs->runAll();
        $deliveries = $this->webhooks->deliveries($hook->id);
        self::assertSame([[2, 200, true], [1, 503, false]], array_map(static fn($delivery): array => [$delivery->attempt, $delivery->status, $delivery->succeeded], $deliveries));
        self::assertSame($deliveries[0]->event, $deliveries[1]->event, 'the same event, so the receiver tells repeats');
        $event = json_decode($this->sender->sent[1][1], true);
        self::assertIsArray($event);
        self::assertSame(['customer' => 7, 'points' => 50], $event['data'], 'an add-on\'s event carries its data');
    }

    public function testAWebhookTurnedOffHearsNothingButItsTest(): void
    {
        $hook = $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED], active: false), 7);

        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->webhooks->ping($hook->id);
        $this->jobs->runAll();

        self::assertCount(1, $this->sender->sent);
        self::assertSame(WebhookTopics::PING, $this->sender->sent[0][2]['X-OrillaCart-Topic']);
    }

    public function testAWebhookIsInTheNameOfWhoSavedItAndStopsWhenTheyNoLongerManageTheShop(): void
    {
        $hook = $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED]), 7);
        self::assertSame([7, 'Mara'], [$hook->ownerId, $hook->owner]);
        self::assertSame(0, $this->webhooks->ownerChanged(7), 'still a manager: nothing changes');

        // Demoted with a capability a plugin took away, which no hook tells of: the next event finds out.
        $this->managers->managers = [8];
        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->jobs->runAll();
        self::assertSame([], $this->sender->sent, 'no order or customer data goes in the name of someone who left');
        $view = $this->webhooks->all()[0];
        self::assertSame([false, Webhook::OFF_OWNER_LEFT], [$view->active, $view->offReason]);
        self::assertTrue(array_any($this->shop->logger->records, static fn(array $record): bool => $record['level'] === 'warning' && str_contains($record['message'], 'no longer manages the shop')), 'the shop is told');

        $this->webhooks->update($hook->id, new SaveWebhook('ERP orders', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED], active: false), 8);
        self::assertSame(Webhook::OFF_OWNER_LEFT, $this->webhooks->all()[0]->offReason, 'left off, it still says why');
        $on = $this->webhooks->update($hook->id, new SaveWebhook('ERP orders', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED]), 8);
        self::assertSame([true, null, 8, 'Ivo'], [$on->active, $on->offReason, $on->ownerId, $on->owner], 'the manager who switched it on answers for it');
        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->jobs->runAll();
        self::assertCount(1, $this->sender->sent);

        // Deleted, or demoted through WordPress: switched off at once.
        $this->managers->managers = [];
        self::assertSame(1, $this->webhooks->ownerChanged(8));
        self::assertSame(0, $this->webhooks->ownerChanged(8), 'off already');
        self::assertFalse($this->webhooks->all()[0]->active);
    }

    public function testAWebhookTakenOffTheSiteWithItsManagerStopsThoughTheyStillHaveTheirRoles(): void
    {
        $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED]), 7);
        $this->webhooks->create(new SaveWebhook('Other', 'https://other.example.com/hook', [WebhookTopics::PRODUCT_CHANGED]), 8);

        self::assertSame(1, $this->webhooks->ownerChanged(7, leaving: true));
        self::assertSame([false, true], array_map(static fn($view): bool => $view->active, $this->webhooks->all()));
    }

    public function testAWebhookWhoseEventsFailInARowIsSwitchedOffAndTheShopIsTold(): void
    {
        $hook = $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED], failureLimit: 2), 7);
        $this->sender->always = new WebhookResponse(null, 'Could not connect', 3);

        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->drain();
        self::assertSame([true, 1], [$this->webhooks->all()[0]->active, $this->webhooks->all()[0]->failedInARow], 'one event failed after every try');
        $this->webhooks->ping($hook->id);
        $this->drain();
        self::assertSame(1, $this->webhooks->all()[0]->failedInARow, 'a test does not count');

        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->drain();
        $view = $this->webhooks->all()[0];
        self::assertSame([false, Webhook::OFF_FAILING, 2], [$view->active, $view->offReason, $view->failedInARow]);
        self::assertTrue(array_any($this->shop->logger->records, static fn(array $record): bool => $record['level'] === 'error' && str_contains($record['message'], 'was switched off')), 'the shop is told');
        $sent = \count($this->sender->sent);
        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->drain();
        self::assertCount($sent, $this->sender->sent, 'a dead address is not tried for every event forever');

        $this->sender->always = null;
        $on = $this->webhooks->update($hook->id, new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED], failureLimit: 2), 7);
        self::assertSame([true, 0, null], [$on->active, $on->failedInARow, $on->offReason], 'switched on, it starts again');
    }

    public function testAnEventTakenStartsTheCountOfFailedOnesAgainAndALimitOfNoneNeverSwitchesOff(): void
    {
        $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED], failureLimit: 0), 7);
        $this->sender->always = new WebhookResponse(500, 'Down', 3);
        foreach ([1, 2, 3] as $event) {
            $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
            $this->drain();
        }
        self::assertSame([true, 3], [$this->webhooks->all()[0]->active, $this->webhooks->all()[0]->failedInARow]);

        $this->sender->always = null;
        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->drain();
        self::assertSame(0, $this->webhooks->all()[0]->failedInARow);

        $this->expectException(IntegrationRuleViolation::class);
        $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED], failureLimit: -1), 7);
    }

    /** Runs the queue until every job is done or failed, each retry's wait passed. */
    private function drain(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->jobs->runAll();
            $this->shop->clock->sleep(6 * 3600);
        }
    }

    public function testTheShopsEventsAreQueuedForWebhooksInsideTheirTransaction(): void
    {
        $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::ORDER_PLACED]), 7);
        $announce = new AnnounceToWebhooks($this->webhooks, $this->shop->orderRecords);
        $this->shop->events->addListener(OrderPaid::class, $announce->orderPaid(...));
        $this->shop->events->addListener(OrderPlaced::class, $announce->orderPlaced(...));
        $queuedAt = [];
        // After the announcement: what the queue holds while the order's transaction is still open.
        $this->shop->events->addListener(OrderPlaced::class, function () use (&$queuedAt): void {
            $queuedAt[] = [$this->shop->transactions->depth > 0, \count($this->jobs->queue->jobs)];
        }, -10);
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));

        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
        $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));

        self::assertSame([[true, 1]], $queuedAt, 'the delivery job is part of the transaction that placed the order');
        $this->jobs->runAll();
        self::assertSame([WebhookTopics::ORDER_PLACED], array_column(array_column($this->sender->sent, 2), 'X-OrillaCart-Topic'), 'the webhook on order.placed alone hears of it');
    }

    public function testAWebhookGoesToHttpsAndToTopicsTheShopHas(): void
    {
        foreach ([['http://erp.example.com/hook', [WebhookTopics::ORDER_PLACED]], ['https://erp.example.com/hook', ['nothing.here']]] as [$url, $topics]) {
            try {
                $this->webhooks->create(new SaveWebhook('ERP', $url, $topics), 7);
                self::fail("{$url} with " . implode(', ', $topics) . ' was taken');
            } catch (IntegrationRuleViolation) {
            }
        }
        self::assertSame('http://localhost:8080/hook', $this->webhooks->create(new SaveWebhook('Test', 'http://localhost:8080/hook', [WebhookTopics::ORDER_PLACED]), 7)->url, 'http on this computer, for testing');
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return $value;
    }
}

final class RecordingSender implements WebhookSender
{
    /** @var list<array{string, string, array<string, string>}> */
    public array $sent = [];
    /** @var list<WebhookResponse> */
    public array $answers = [];
    /** What every post is answered, once the answers above are used up. */
    public ?WebhookResponse $always = null;

    public function post(string $url, string $body, array $headers): WebhookResponse
    {
        $this->sent[] = [$url, $body, $headers];

        return array_shift($this->answers) ?? $this->always ?? new WebhookResponse(200, '', 5);
    }
}

final class InMemoryWebhooks implements WebhookRepository
{
    /** @var array<int, Webhook> */
    private array $webhooks = [];
    /** @var list<WebhookDelivery> */
    private array $deliveries = [];

    public function all(): array
    {
        return array_values($this->webhooks);
    }

    public function find(int $id): ?Webhook
    {
        return $this->webhooks[$id] ?? null;
    }

    public function save(Webhook $webhook): void
    {
        if (!\in_array($webhook, $this->webhooks, true)) {
            $id = \count($this->webhooks) + 1;
            new \ReflectionProperty(Webhook::class, 'id')->setValue($webhook, $id);
            $this->webhooks[$id] = $webhook;
        }
    }

    public function remove(Webhook $webhook): void
    {
        unset($this->webhooks[$webhook->id()]);
    }

    public function record(WebhookDelivery $delivery): void
    {
        new \ReflectionProperty(WebhookDelivery::class, 'id')->setValue($delivery, \count($this->deliveries) + 1);
        $this->deliveries[] = $delivery;
    }

    public function deliveries(int $webhookId, int $limit): array
    {
        return \array_slice(array_values(array_reverse(array_filter($this->deliveries, static fn(WebhookDelivery $delivery): bool => $delivery->webhookId() === $webhookId))), 0, $limit);
    }
}
