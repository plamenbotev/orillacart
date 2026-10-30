<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Application;

use OrillaCart\Extension\Marketing\MailingList;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Newsletter\Application\Dto\MailingListView;
use OrillaCart\Newsletter\Application\Dto\NewsletterSettingsView;
use OrillaCart\Newsletter\Application\Dto\SubscriptionView;
use OrillaCart\Newsletter\Application\Dto\UpdateNewsletterSettings;
use OrillaCart\Newsletter\Domain\NewsletterRuleViolation;
use OrillaCart\Newsletter\Domain\NewsletterSettings;
use OrillaCart\Newsletter\Domain\NewsletterSettingsRepository;
use OrillaCart\Newsletter\Domain\SignUpSource;
use OrillaCart\Newsletter\Domain\Subscriber;
use OrillaCart\Newsletter\Domain\SubscriberRepository;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shared\Lifecycle\Languages;
use Psr\Clock\ClockInterface;

/**
 * The shop's newsletter: who signed up, with the words they agreed to, when and where. The shop keeps them all;
 * the mailing lists the shop manager turned on (add-ons') hear of each sign-up and each unsubscribe, through a
 * job (SyncSubscriber), so a newsletter service that is slow or down never holds up an order.
 */
final readonly class Newsletter
{
    /** The job that emails the link confirming a sign-up (SendNewsletterConfirmation). */
    public const string CONFIRM_JOB = 'mail.newsletter_confirm';

    /** How many sign-ups a job lot sends a newly turned-on list. */
    private const int BATCH = 500;

    public function __construct(
        private SubscriberRepository $subscribers,
        private NewsletterSettingsRepository $settings,
        private InstalledExtensions $extensions,
        private Jobs $jobs,
        private ClockInterface $clock,
        private Languages $languages,
    ) {}

    public function settings(): NewsletterSettingsView
    {
        $settings = $this->settings->load();

        return new NewsletterSettingsView(
            $settings->offered,
            $settings->consent,
            array_map(static fn(MailingList $list): MailingListView => new MailingListView($list->id(), $list->name(), \in_array($list->id(), $settings->lists, true)), $this->extensions->mailingLists()),
            $this->subscribers->countSubscribed(),
            $settings->confirmNew,
        );
    }

    /**
     * A list turned on gets everyone subscribed so far.
     *
     * @throws NewsletterRuleViolation for a list no add-on brings, or words too long
     */
    public function updateSettings(UpdateNewsletterSettings $command): NewsletterSettingsView
    {
        $known = array_map(static fn(MailingList $list): string => $list->id(), $this->extensions->mailingLists());
        $unknown = array_diff($command->lists, $known);
        if ($unknown !== []) {
            throw new NewsletterRuleViolation('No add-on brings the mailing list %s.', 'orillacart_unknown_mailing_list', values: [implode(', ', $unknown)]);
        }
        $before = $this->settings->load();
        $lists = array_values(array_unique($command->lists));
        $this->settings->save(new NewsletterSettings($command->offered, trim($command->consent), $lists, $command->confirmNew));
        foreach (array_diff($lists, $before->lists) as $list) {
            $this->sendEveryone($list);
        }

        return $this->settings();
    }

    /** The words of the box to tick, when the shop offers the newsletter; null when it does not. */
    public function offer(): ?string
    {
        $settings = $this->settings->load();

        return $settings->offered ? $settings->consent : null;
    }

    public function isSubscribed(string $email): bool
    {
        return $this->subscribers->findByEmail($email)?->isSubscribed() ?? false;
    }

    public function subscription(string $email): ?SubscriptionView
    {
        $subscriber = $this->subscribers->findByEmail($email);

        return $subscriber === null ? null : SubscriptionView::of($subscriber);
    }

    /**
     * Someone ticked the box: they are subscribed, with the words the box had, and the lists hear of it. Signing
     * up again while subscribed changes nothing. When the shop confirms new sign-ups by email (the default, as the
     * GDPR expects one to prove it), they are asked to, and only once they followed the link are they on the
     * newsletter and do the lists hear of them: nobody signs up an address that is not theirs.
     *
     * @throws NewsletterRuleViolation when the shop does not offer the newsletter
     */
    public function subscribe(string $email, string $firstName, string $lastName, SignUpSource $source, ?int $customerId = null): void
    {
        $consent = $this->offer() ?? throw new NewsletterRuleViolation('The shop has no newsletter to sign up for.', 'orillacart_no_newsletter');
        $confirm = $this->settings->load()->confirmNew;
        $subscriber = $this->subscribers->findByEmail($email);
        if ($subscriber === null) {
            $subscriber = new Subscriber($email, $firstName, $lastName, $consent, $source, $this->clock->now(), $customerId, $confirm);
        } elseif (!$subscriber->subscribe($firstName, $lastName, $consent, $source, $this->clock->now(), $customerId, $confirm)) {
            return;
        }
        $this->subscribers->save($subscriber);
        if ($subscriber->isPending()) {
            // Asked in the language they signed up in, by a job in the mail lane, as the shop's other emails.
            $this->jobs->push(self::CONFIRM_JOB, ['email' => $subscriber->email(), 'locale' => $this->languages->current()], "confirm:{$subscriber->email()}");

            return;
        }
        $this->sync($subscriber->email());
    }

    /**
     * The owner of the email followed the link that confirms their sign-up: they are on the newsletter, and the
     * lists hear of it.
     *
     * @return bool false for an email not waiting to be confirmed: confirmed already, unsubscribed or unknown
     */
    public function confirm(string $email): bool
    {
        $subscriber = $this->subscribers->findByEmail($email);
        if ($subscriber === null || !$subscriber->confirm($this->clock->now())) {
            return false;
        }
        $this->subscribers->save($subscriber);
        $this->sync($subscriber->email());

        return true;
    }

    /** Whether the email signed up and has yet to confirm it. */
    public function isPending(string $email): bool
    {
        return $this->subscribers->findByEmail($email)?->isPending() ?? false;
    }

    public function unsubscribe(string $email): void
    {
        $subscriber = $this->subscribers->findByEmail($email);
        if ($subscriber !== null && $subscriber->unsubscribe($this->clock->now())) {
            $this->subscribers->save($subscriber);
            $this->sync($subscriber->email());
        }
    }

    /**
     * The privacy tools erase the person: the shop forgets the sign-up, and every list it knows (the ones it no
     * longer sends sign-ups to included) deletes them, or takes them off when it cannot delete.
     *
     * @return bool whether there was one
     */
    public function erase(string $email): bool
    {
        $subscriber = $this->subscribers->findByEmail($email);
        if ($subscriber === null) {
            return false;
        }
        $this->subscribers->remove($subscriber);
        foreach ($this->extensions->mailingLists() as $list) {
            $this->jobs->push(SyncSubscriber::TYPE, ['list' => $list->id(), 'email' => $subscriber->email(), 'forget' => true], "forget:{$list->id()}:{$subscriber->email()}");
        }

        return true;
    }

    /**
     * A list's service says the person unsubscribed there (from its own link): they are off the shop's newsletter
     * too, and the other lists hear it, so turning on another list never signs them up again.
     */
    public function unsubscribedAtList(string $email): void
    {
        $this->unsubscribe($email);
    }

    /**
     * The person changed their email address: the sign-up follows it, and the lists hear of both addresses. One the
     * new address had already is merged with it: the subscribed one of the two stays, the new address's when both
     * are, and the old address is off the newsletter either way.
     */
    public function changeEmail(string $from, string $to): void
    {
        $subscriber = $this->subscribers->findByEmail($from);
        if ($subscriber === null || Subscriber::normalize($from) === Subscriber::normalize($to)) {
            return;
        }
        $existing = $this->subscribers->findByEmail($to);
        if ($existing !== null) {
            // The old address's sign-up takes the new one's place only when it holds more: subscribed where the new
            // address's is not, or waiting to be confirmed where the new address's was left.
            $moves = $subscriber->isSubscribed() && !$existing->isSubscribed() || $subscriber->isPending() && !$existing->isSubscribed() && !$existing->isPending();
            if (!$moves) {
                // The new address's own sign-up stands: the old one's goes.
                $this->subscribers->remove($subscriber);
                $this->sync(Subscriber::normalize($from));
                $this->sync($existing->email());

                return;
            }
            $this->subscribers->remove($existing);
        }
        $subscriber->changeEmail($to);
        $this->subscribers->save($subscriber);
        $this->sync(Subscriber::normalize($from));
        $this->sync($subscriber->email());
    }

    /**
     * Everyone subscribed now, a page at a time, for the shop's export.
     *
     * @return iterable<SubscriptionView>
     */
    public function everyone(): iterable
    {
        $after = 0;
        do {
            $page = $this->subscribers->subscribed($after, self::BATCH);
            foreach ($page as $subscriber) {
                yield SubscriptionView::of($subscriber);
                $after = $subscriber->id();
            }
        } while (\count($page) === self::BATCH);
    }

    /** Each list the sign-ups go to hears how the email stands now. */
    private function sync(string $email): void
    {
        foreach ($this->settings->load()->lists as $list) {
            $this->jobs->push(SyncSubscriber::TYPE, ['list' => $list, 'email' => $email], "{$list}:{$email}");
        }
    }

    private function sendEveryone(string $list): void
    {
        $after = 0;
        do {
            $page = $this->subscribers->subscribed($after, self::BATCH);
            $this->jobs->pushMany(SyncSubscriber::TYPE, array_map(static fn(Subscriber $subscriber): array => ['key' => "{$list}:{$subscriber->email()}", 'payload' => ['list' => $list, 'email' => $subscriber->email()]], $page));
            $after = $page === [] ? $after : $page[\count($page) - 1]->id();
        } while (\count($page) === self::BATCH);
    }

    /**
     * How the email stands now, for a list: subscribed (with what they agreed to), or not.
     *
     * @internal for SyncSubscriber
     */
    public function standing(string $email): ?Subscriber
    {
        $subscriber = $this->subscribers->findByEmail($email);

        return $subscriber?->isSubscribed() === true ? $subscriber : null;
    }

    /**
     * The list of the id, when the sign-ups still go to it; with $any, any list an add-on registers.
     *
     * @internal for SyncSubscriber
     */
    public function list(string $id, bool $any = false): ?MailingList
    {
        if (!$any && !\in_array($id, $this->settings->load()->lists, true)) {
            return null;
        }
        foreach ($this->extensions->mailingLists() as $list) {
            if ($list->id() === $id) {
                return $list;
            }
        }

        return null;
    }
}
