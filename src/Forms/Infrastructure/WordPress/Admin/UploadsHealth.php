<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure\WordPress\Admin;

use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Forms\Domain\FieldKind;
use OrillaCart\Forms\Domain\FormField;
use OrillaCart\Forms\Domain\FormPlacement;
use OrillaCart\Forms\Domain\FormRepository;
use OrillaCart\Forms\Domain\FormUploadRepository;
use OrillaCart\Forms\Domain\UploadInspector;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the files shoppers send can be taken as the shop's forms ask: the server reads a file's type from its
 * content (fileinfo) and writes pictures again (GD); and how much the files no order took come to.
 */
final readonly class UploadsHealth implements HealthCheck
{
    public function __construct(
        private FormRepository $forms,
        private FormUploadRepository $uploads,
        private UploadInspector $inspector,
    ) {}

    public static function id(): string
    {
        return 'form_uploads';
    }

    public static function label(): string
    {
        return __('Files shoppers send', 'orillacart');
    }

    public function check(): HealthStatus
    {
        $types = [];
        foreach ($this->forms->active(FormPlacement::Product) as $form) {
            foreach ($form->fields() as $field) {
                if ($field->kind === FieldKind::File) {
                    array_push($types, ...$field->types);
                }
            }
        }
        if ($types === []) {
            return HealthStatus::good(__('No form asks for a file', 'orillacart'));
        }
        if (!$this->inspector->readsTypes()) {
            return HealthStatus::critical(
                __('The shop cannot take the files its forms ask for', 'orillacart'),
                __('PHP’s fileinfo extension tells what a file really is; without it every file is refused. Ask the host to turn it on.', 'orillacart'),
            );
        }
        if (!$this->inspector->takesImages() && array_filter($types, static fn(string $type): bool => str_starts_with($type, 'image/')) !== []) {
            return HealthStatus::recommended(
                __('The shop cannot take pictures its forms ask for', 'orillacart'),
                __('Pictures are written again as new files before they are kept, which needs PHP’s GD extension; without it file fields take PDFs only. Ask the host to turn it on.', 'orillacart'),
            );
        }
        if ($this->uploads->untakenBytes() > FormUploads::MAX_UNTAKEN_BYTES * 0.8 || $this->uploads->unorderedBytes() > FormUploads::MAX_UNORDERED_BYTES * 0.8) {
            return HealthStatus::recommended(
                __('Files no cart or order took fill most of their room', 'orillacart'),
                __('The shop stops taking files while those no cart or order took come to 500 MB, or all those no order took to 2 GB; each visitor sends up to 100 MB a day. They are deleted after two days, or a week after a cart last took them.', 'orillacart'),
            );
        }

        return HealthStatus::good(__('The shop takes the files its forms ask for', 'orillacart'), __('Their type is read from their content and pictures are written again before they are kept privately.', 'orillacart'));
    }

    public function info(): array
    {
        return [
            'fileinfo' => ['label' => 'fileinfo', 'value' => $this->inspector->readsTypes() ? __('yes', 'orillacart') : __('no', 'orillacart')],
            'gd' => ['label' => 'GD', 'value' => $this->inspector->takesImages() ? __('yes', 'orillacart') : __('no', 'orillacart')],
            'untaken' => ['label' => __('Files no cart or order took', 'orillacart'), 'value' => (string) size_format($this->uploads->untakenBytes())],
            'largest' => ['label' => __('Largest file a field may take', 'orillacart'), 'value' => FormField::MAX_MEGABYTES . ' MB'],
        ];
    }
}
