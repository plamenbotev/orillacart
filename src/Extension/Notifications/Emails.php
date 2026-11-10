<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Notifications;

/**
 * The shop's email, for add-ons writing to customers or the shop (API 22): the message goes in the shop's own
 * email layout, from the shop's address, in the site's language, as every email of the shop's does.
 */
interface Emails
{
    /**
     * @param string      $html    the body: paragraphs, lists, links and tables; scripts and the like are taken out
     * @param string|null $replyTo where an answer goes, when not to the shop
     *
     * @return bool whether the mail system accepted it
     */
    public function send(string $to, string $subject, string $html, ?string $replyTo = null): bool;
}
