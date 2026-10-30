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
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    private TestShop $shop;
    private TestJobs $jobs;
    private InMemoryWebhooks $repository;
    private RecordingSender $sender;
    private Webhooks $webhooks;

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
        $this->jobs->with(new DeliverWebhook($this->repository, new WebhookPayloads($this->shop->orders, $this->shop->products, $normalizer), $this->sender, $this->shop->clock));
        $this->webhooks = new Webhooks($this->repository, $topics, $this->jobs->jobs, $this->shop->clock);
    }

    public function testAnEventGoesToTheWebhooksThatListenSignedWithTheirSecret(): void
    {
        $hook = $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED]));
        $this->webhooks->create(new SaveWebhook('Orders only', 'https://other.example.com/hook', [WebhookTopics::ORDER_PLACED]));

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
        $hook = $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED]));
        $this->repository->find($hook->id)?->newSecret('');

        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->jobs->runAll();

        self::assertSame([], $this->sender->sent);
        self::assertNotNull($this->webhooks->newSecret($hook->id)->secret, 'a new one is shown');
    }

    public function testAFailedDeliveryIsTriedAgainAndEachTryKept(): void
    {
        $hook = $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', ['loyalty.points']));
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
        $hook = $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::PRODUCT_CHANGED], active: false));

        $this->webhooks->announce(WebhookTopics::PRODUCT_CHANGED, ['product' => 20]);
        $this->webhooks->ping($hook->id);
        $this->jobs->runAll();

        self::assertCount(1, $this->sender->sent);
        self::assertSame(WebhookTopics::PING, $this->sender->sent[0][2]['X-OrillaCart-Topic']);
    }

    public function testTheShopsEventsAreQueuedForWebhooksInsideTheirTransaction(): void
    {
        $this->webhooks->create(new SaveWebhook('ERP', 'https://erp.example.com/hook', [WebhookTopics::ORDER_PLACED]));
        $announce = new AnnounceToWebhooks($this->webhooks);
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
                $this->webhooks->create(new SaveWebhook('ERP', $url, $topics));
                self::fail("{$url} with " . implode(', ', $topics) . ' was taken');
            } catch (IntegrationRuleViolation) {
            }
        }
        self::assertSame('http://localhost:8080/hook', $this->webhooks->create(new SaveWebhook('Test', 'http://localhost:8080/hook', [WebhookTopics::ORDER_PLACED]))->url, 'http on this computer, for testing');
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

    public function post(string $url, string $body, array $headers): WebhookResponse
    {
        $this->sent[] = [$url, $body, $headers];

        return array_shift($this->answers) ?? new WebhookResponse(200, '', 5);
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
