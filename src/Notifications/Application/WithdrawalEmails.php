<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Returns\Domain\Event\WithdrawalDeclared;
use Psr\Log\LoggerInterface;

/**
 * The acknowledgement of a withdrawal, on a durable medium and at once, as the law asks: what the consumer
 * declared, and the date and time it was sent. It goes to the address they gave, and to the order's own when that
 * is another, so an order's owner hears of a withdrawal someone else made. The shop cannot turn it off.
 */
final readonly class WithdrawalEmails
{
    public const string KIND = 'withdrawal_received';
    private const string TEMPLATE = 'emails/withdrawal-received.html.twig';

    public function __construct(
        private EmailWriter $writer,
        private Mailer $mailer,
        private LoggerInterface $logger,
    ) {}

    public function acknowledge(WithdrawalDeclared $withdrawal): void
    {
        $context = [
            'number' => $withdrawal->orderNumber,
            'name' => $withdrawal->name,
            'items' => $withdrawal->items,
            'declared_at' => $withdrawal->declaredAt,
            'confirmation_email' => $withdrawal->confirmationEmail,
        ];
        $to = array_values(array_unique(array_map(strtolower(...), [$withdrawal->confirmationEmail, $withdrawal->orderEmail])));
        foreach ($to as $address) {
            try {
                $email = $this->writer->write(self::TEMPLATE, self::KIND, $address, $withdrawal->locale, $context, null, $withdrawal->orderNumber);
            } catch (EmailNotWritten $e) {
                $this->logger->error('The acknowledgement of the withdrawal from order {order} could not be written: {message}', ['order' => $withdrawal->orderNumber, 'message' => $e->getMessage(), 'exception' => $e]);

                return;
            }
            if (!$this->mailer->send($email)) {
                $this->logger->error('The acknowledgement of the withdrawal from order {order} was not sent to {to}: the mail system refused it. Send it by hand.', ['order' => $withdrawal->orderNumber, 'to' => $address]);
            }
        }
    }
}
