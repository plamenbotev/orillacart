<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

use OrillaCart\Notifications\Application\EmailTemplateEditor;
use OrillaCart\Notifications\Domain\EmailTemplate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: show an email as a template would make it, saved or as edited and not saved yet.
 *
 * @param string|null $source the template as edited; null for the one in use
 * @param string|null $kind   the email to show; null for the one the template makes
 * @param int|null    $order  the order an order email is about; null for the latest
 */
final readonly class PreviewEmail
{
    public function __construct(
        #[Assert\Length(max: EmailTemplate::MAX_LENGTH)]
        public ?string $source = null,
        #[Assert\Choice(callback: [EmailTemplateEditor::class, 'previewKinds'])]
        public ?string $kind = null,
        #[Assert\Positive]
        public ?int $order = null,
        /** The language's template, written in it, a WordPress locale such as de_DE; empty for every language. */
        #[Assert\Regex(pattern: '/^([a-z]{2,3}(_[A-Za-z0-9]{2,8}){0,2})?$/')]
        public string $locale = '',
    ) {}
}
