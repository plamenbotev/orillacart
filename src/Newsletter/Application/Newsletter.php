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
use Psr\Clock\ClockInterface;

/**
 * The shop's newsletter: who signed up, with the words they agreed to, when and where. The shop keeps them all;
 * the mailing lists the shop manager turned on (add-ons') hear of each sign-up and each unsubscribe, through a
 * job (SyncSubscriber), so a newsletter service that is slow or down never holds up an order.
 */
final readonly class Newsletter
{
    /** How many sign-ups a job lot sends a newly turned-on list. */
    private const int BATCH = 500;

    public function __construct(
        private SubscriberRepository $subscribers,
        private NewsletterSettingsRepository $settings,
        private InstalledExtensions $extensions,
        private Jobs $jobs,
        private ClockInterface $clock,
    ) {}

    public function settings(): NewsletterSettingsView
    {
        $settings = $this->settings->load();

        return new NewsletterSettingsView(
            $settings->offered,
            $settings->consent,
            array_map(static fn(MailingList $list): MailingListView => new MailingListView($list->id(), $list->name(), \in_array($list->id(), $settings->lists, true)), $this->extensions->mailingLists()),
            $this->subscribers->countSubscribed(),
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
            throw new NewsletterRuleViolation(\sprintf('No add-on brings the mailing list %s.', implode(', ', $unknown)), 'orillacart_unknown_mailing_list');
        }
        $before = $this->settings->load();
        $lists = array_values(array_unique($command->lists));
        $this->settings->save(new NewsletterSettings($command->offered, trim($command->consent), $lists));
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
     * up again while subscribed changes nothing.
     *
     * @throws NewsletterRuleViolation when the shop does not offer the newsletter
     */
    public function subscribe(string $email, string $firstName, string $lastName, SignUpSource $source, ?int $customerId = null): void
    {
        $consent = $this->offer() ?? throw new NewsletterRuleViolation('The shop has no newsletter to sign up for.', 'orillacart_no_newsletter');
        $subscriber = $this->subscribers->findByEmail($email);
        if ($subscriber === null) {
            $subscriber = new Subscriber($email, $firstName, $lastName, $consent, $source, $this->clock->now(), $customerId);
        } elseif (!$subscriber->subscribe($firstName, $lastName, $consent, $source, $this->clock->now(), $customerId)) {
            return;
        }
        $this->subscribers->save($subscriber);
        $this->sync($subscriber->email());
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

    /** The person changed their email address: the sign-up follows it, and the lists hear of both addresses. */
    public function changeEmail(string $from, string $to): void
    {
        $subscriber = $this->subscribers->findByEmail($from);
        if ($subscriber === null || Subscriber::normalize($from) === Subscriber::normalize($to) || $this->subscribers->findByEmail($to) !== null) {
            return;
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
