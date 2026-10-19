<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\CreatesShipments;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShipmentItem;
use OrillaCart\Extension\Shipping\ShipmentRequest;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Extension\Shipping\Tracking;
use OrillaCart\Extension\Shipping\TracksShipments;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shipping\Application\Dto\BookShipment;
use OrillaCart\Shipping\Application\Dto\ConsignmentView;
use OrillaCart\Shipping\Application\Dto\LabelView;
use OrillaCart\Shipping\Application\Dto\OrderShipmentsView;
use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\Consignment;
use OrillaCart\Shipping\Domain\ConsignmentNotFound;
use OrillaCart\Shipping\Domain\ConsignmentRepository;
use OrillaCart\Shipping\Domain\ConsignmentStatus;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Shipments the shop books with couriers from the order screen, through the shipping method of the carrier the
 * customer chose: the label to print, cancelling one the courier has not picked up, and tracking, which
 * completes a shipped order once the courier delivered it.
 */
final readonly class Consignments
{
    private const string CASH_ON_DELIVERY = 'cash_on_delivery';
    private const int TRACKED_PER_RUN = 1000;

    public function __construct(
        private ConsignmentRepository $consignments,
        private CarrierRepository $carriers,
        private ShippingMethods $methods,
        private OrderService $orders,
        private ProductQuotes $products,
        private SettingsService $settings,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws OrderNotFound
     */
    public function forOrder(int $orderId): OrderShipmentsView
    {
        $order = $this->orders->order($orderId);
        $booking = $this->booking($order);

        return new OrderShipmentsView(
            $booking !== null && !\in_array($order->status, ['cancelled', 'failed', 'refunded'], true),
            $booking === null ? null : $booking['carrier']->name(),
            array_map(ConsignmentView::fromConsignment(...), $this->consignments->forOrder($orderId)),
        );
    }

    /**
     * @throws OrderNotFound
     * @throws ShippingRuleViolation for an order its carrier cannot book, one already on its way, or a courier that refused
     */
    public function book(int $orderId, BookShipment $command, ?int $userId): ConsignmentView
    {
        $order = $this->orders->order($orderId);
        $booking = $this->booking($order) ?? throw new ShippingRuleViolation('The order\'s way to ship does not book shipments with a courier.', 'orillacart_shipment_not_bookable');
        if (\in_array($order->status, ['cancelled', 'failed', 'refunded'], true)) {
            throw new ShippingRuleViolation(\sprintf('Order #%d is %s; it does not ship.', $orderId, $order->status), 'orillacart_shipment_not_bookable');
        }
        foreach ($this->consignments->forOrder($orderId) as $existing) {
            if ($existing->status()->isOpen()) {
                throw new ShippingRuleViolation(\sprintf('Shipment %s is on its way already; cancel it to book another.', $existing->number()), 'orillacart_shipment_exists');
            }
        }

        $address = $order->shippingAddress ?? $order->billing;
        try {
            $booked = $booking['method']->createShipment(new ShipmentRequest(
                "Order #{$order->number}",
                $booking['service'],
                trim("{$address->firstName} {$address->lastName}"),
                $address->phone,
                $order->email,
                $address->street,
                $address->city,
                $address->postcode,
                $address->country,
                $address->state,
                $order->pickupPointId,
                $this->contents($order),
                $order->paymentGateway === self::CASH_ON_DELIVERY && $order->paidAt === null ? $order->total : null,
                $order->subtotal,
                $command->parcels,
            ), $booking['settings']);
        } catch (ProviderUnavailable $e) {
            throw new ShippingRuleViolation(\sprintf('The courier did not book the shipment: %s', $e->getMessage()), 'orillacart_courier_refused', $e);
        } catch (\Throwable $e) {
            throw $this->broken('booking', $e);
        }

        $consignment = new Consignment($orderId, $booking['carrier']->id(), $booking['carrier']->name(), $booking['method']->id(), $booked->number, $booked->trackingUrl, $this->clock->now());
        $this->consignments->save($consignment);
        $this->orders->addNote($orderId, new AddOrderNote(\sprintf('Shipment %s booked with %s.', $booked->number, $booking['carrier']->name())), $userId);

        return ConsignmentView::fromConsignment($consignment);
    }

    /**
     * @throws ConsignmentNotFound
     * @throws ShippingRuleViolation when the courier cannot give it
     */
    public function label(int $id): LabelView
    {
        $consignment = $this->get($id);
        [$method, $settings] = $this->methodOf($consignment);
        try {
            $label = $method->label($consignment->number(), $settings);
        } catch (ProviderUnavailable $e) {
            throw new ShippingRuleViolation(\sprintf('The courier did not give the label: %s', $e->getMessage()), 'orillacart_courier_refused', $e);
        } catch (\Throwable $e) {
            throw $this->broken('a label', $e);
        }
        $extension = $label->mimeType === 'application/pdf' ? 'pdf' : (str_starts_with($label->mimeType, 'image/') ? substr($label->mimeType, 6) : 'bin');

        return new LabelView("label-{$consignment->number()}.{$extension}", $label->mimeType, base64_encode($label->content));
    }

    /**
     * @throws ConsignmentNotFound
     * @throws ShippingRuleViolation when the courier has it already, or refuses
     */
    public function cancel(int $id, ?int $userId): ConsignmentView
    {
        $consignment = $this->get($id);
        if ($consignment->status() !== ConsignmentStatus::Booked) {
            throw new ShippingRuleViolation(\sprintf('Shipment %s is %s; only a booked one is cancelled.', $consignment->number(), str_replace('_', ' ', $consignment->status()->value)), 'orillacart_shipment_not_cancellable');
        }
        [$method, $settings] = $this->methodOf($consignment);
        try {
            $method->cancelShipment($consignment->number(), $settings);
        } catch (ProviderUnavailable $e) {
            throw new ShippingRuleViolation(\sprintf('The courier did not cancel the shipment: %s', $e->getMessage()), 'orillacart_courier_refused', $e);
        } catch (\Throwable $e) {
            throw $this->broken('cancelling', $e);
        }
        $consignment->cancel($this->clock->now());
        $this->consignments->save($consignment);
        $this->orders->addNote($consignment->orderId(), new AddOrderNote(\sprintf('Shipment %s cancelled.', $consignment->number())), $userId);

        return ConsignmentView::fromConsignment($consignment);
    }

    /**
     * The shipments on their way, for TrackConsignments to ask their couriers about.
     *
     * @return list<int>
     */
    public function onTheWay(): array
    {
        return array_map(static fn(Consignment $consignment): int => $consignment->id(), $this->consignments->open(self::TRACKED_PER_RUN));
    }

    /**
     * Asks the courier where the shipment is, and the order follows: the first word that it is on its way ships
     * an order being prepared (and tells the customer), delivery completes it, and a return is noted for the shop.
     *
     * @throws \Throwable when the courier cannot answer now: ask again later
     *
     * @return bool whether it changed
     */
    public function track(int $consignmentId): bool
    {
        $consignment = $this->consignments->find($consignmentId);
        if ($consignment === null) {
            return false;
        }
        try {
            [$method, $settings] = $this->methodOf($consignment);
        } catch (ShippingRuleViolation) {
            // Its add-on is gone: nobody to ask.
            return false;
        }
        if (!$method instanceof TracksShipments) {
            return false;
        }
        $tracking = $method->track($consignment->number(), $settings);
        $status = match ($tracking->status) {
            Tracking::DELIVERED => ConsignmentStatus::Delivered,
            Tracking::RETURNED => ConsignmentStatus::Returned,
            default => ConsignmentStatus::OnTheWay,
        };
        if (!$consignment->track($status, $tracking->description, $this->clock->now())) {
            return false;
        }
        $this->consignments->save($consignment);
        $this->orderFollows($consignment, $status);

        return true;
    }

    private function orderFollows(Consignment $consignment, ConsignmentStatus $status): void
    {
        $orderId = $consignment->orderId();
        try {
            $order = $this->orders->order($orderId)->status;
            match (true) {
                $status === ConsignmentStatus::OnTheWay && $order === 'processing' => $this->orders->changeStatus($orderId, new ChangeOrderStatus('shipped', note: \sprintf('The courier picked up shipment %s.', $consignment->number())), null),
                $status === ConsignmentStatus::Delivered && \in_array($order, ['processing', 'shipped'], true) => $this->orders->changeStatus($orderId, new ChangeOrderStatus('completed', note: \sprintf('The courier delivered shipment %s.', $consignment->number())), null),
                $status === ConsignmentStatus::Returned => $this->orders->addNote($orderId, new AddOrderNote(\sprintf('The courier is bringing shipment %s back: %s', $consignment->number(), $consignment->tracking() === '' ? 'no reason given.' : $consignment->tracking())), null),
                default => null,
            };
        } catch (RuleViolation $e) {
            $this->logger->warning('Order {order} did not follow shipment {shipment}: {message}', ['order' => $orderId, 'shipment' => $consignment->number(), 'message' => $e->getMessage()]);
        }
    }

    /**
     * The carrier, its method and the service the customer chose, when the method books shipments.
     *
     * @return array{carrier: Carrier, method: ShippingMethod&CreatesShipments, service: string, settings: Settings}|null
     */
    private function booking(OrderView $order): ?array
    {
        if ($order->shippingOption === null || preg_match('/^carrier:(\d+):([a-z0-9_]{1,40})$/', $order->shippingOption, $parts) !== 1) {
            return null;
        }
        $carrier = $this->carriers->find((int) $parts[1]);
        $method = $carrier?->method() === null ? null : $this->methods->find($carrier->method());
        if ($carrier === null || !$method instanceof CreatesShipments) {
            return null;
        }

        return ['carrier' => $carrier, 'method' => $method, 'service' => $parts[2], 'settings' => new Settings($carrier->methodSettings())];
    }

    /**
     * The shipping method of an add-on broke: logged, and told to the shop as the courier failing.
     */
    private function broken(string $doing, \Throwable $e): ShippingRuleViolation
    {
        $this->logger->error('The shipping method failed {doing}: {message}', ['doing' => $doing, 'message' => $e->getMessage(), 'exception' => $e]);

        return new ShippingRuleViolation(\sprintf('The shipping method failed: %s', $e->getMessage()), 'orillacart_courier_failed', $e);
    }

    /**
     * @return array{ShippingMethod&CreatesShipments, Settings}
     *
     * @throws ShippingRuleViolation when the carrier or its method is gone
     */
    private function methodOf(Consignment $consignment): array
    {
        $carrier = $this->carriers->find($consignment->carrierId());
        $method = $this->methods->find($consignment->method());
        if (!$carrier instanceof Carrier || !$method instanceof CreatesShipments) {
            throw new ShippingRuleViolation(\sprintf('The shipping method that booked shipment %s is not active.', $consignment->number()), 'orillacart_unknown_shipping_method');
        }

        return [$method, new Settings($carrier->methodSettings())];
    }

    /**
     * What ships, measured as the catalog measures it now.
     */
    private function contents(OrderView $order): Shipment
    {
        $shop = $this->settings->current();
        $address = $order->shippingAddress ?? $order->billing;
        $items = [];
        foreach ($order->lines as $line) {
            \assert($line instanceof OrderLineView);
            try {
                $quote = $this->products->quote(new QuoteRequest($line->productId, $line->optionIds, bundleChoices: $line->bundleChoices));
            } catch (RuleViolation) {
                $items[] = new ShipmentItem($line->quantity, name: $line->name);
                continue;
            }
            if ($quote->type === 'physical') {
                $items[] = new ShipmentItem($line->quantity, $quote->weight, $quote->length, $quote->width, $quote->height, $line->name);
            }
        }

        return new Shipment($address->country, $address->state, $address->postcode, $items, $order->subtotal, $order->currency, $shop->weightUnit, $shop->dimensionUnit);
    }

    private function get(int $id): Consignment
    {
        return $this->consignments->find($id) ?? throw new ConsignmentNotFound($id);
    }
}
