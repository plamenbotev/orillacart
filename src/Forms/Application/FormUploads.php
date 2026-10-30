<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Forms\Application\Dto\SendUpload;
use OrillaCart\Forms\Application\Dto\SentUpload;
use OrillaCart\Forms\Domain\CleanText;
use OrillaCart\Forms\Domain\FormField;
use OrillaCart\Forms\Domain\FormRuleViolation;
use OrillaCart\Forms\Domain\FormUpload;
use OrillaCart\Forms\Domain\FormUploadRepository;
use OrillaCart\Forms\Domain\UploadInspector;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\Files\PrivateFiles;
use Psr\Clock\ClockInterface;

/**
 * Files shoppers send to answer file fields. Each is checked for what it really is, kept as a clean copy among
 * the shop's private files, and named by a secret token; nobody downloads it but the shop's managers.
 */
final readonly class FormUploads
{
    /** What temporary files of uploads are called, before the site's id: see temporaryPrefix(). */
    public const string TEMPORARY_PREFIX = 'orillacart-upload';
    /** The largest file any field takes. */
    public const int MAX_MEGABYTES = FormField::MAX_MEGABYTES;
    /** While the uploads no cart or order took come to this, no more are taken: nobody fills the disk. */
    public const int MAX_UNTAKEN_BYTES = 500 * 1024 * 1024;
    /** And while every upload no order took, in carts too, comes to this: the most disk they ever take. */
    public const int MAX_UNORDERED_BYTES = 2 * 1024 * 1024 * 1024;
    /** What one sender (an address, or an IPv6 network) may send in a day. */
    public const int MAX_SENT_A_DAY = 100 * 1024 * 1024;
    /** The most files one cart holds. */
    public const int MAX_HELD = 10;
    /** How long an upload no cart or order took is kept. */
    public const string KEPT_FOR = '-2 days';
    /** How long one in a cart is kept, from when the cart last took it: a week, so carts cannot keep the disk full. */
    public const string HELD_FOR = '-7 days';

    private const array EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];

    /**
     * What one site's temporary files of uploads are called: the sweeper of one site touches only its own, though
     * every site of the host shares the temporary directory.
     */
    public static function temporaryPrefix(int $siteId): string
    {
        return self::TEMPORARY_PREFIX . '-' . $siteId . '-';
    }

    public function __construct(
        private ShopForms $forms,
        private ProductContent $products,
        private UploadInspector $inspector,
        private PrivateFiles $files,
        private FormUploadRepository $uploads,
        private ClockInterface $clock,
        private SettingsService $settings,
        private ProductTranslations $translations,
    ) {}

    /**
     * @throws InvalidFields as "file", with what is wrong
     */
    public function send(SendUpload $command): SentUpload
    {
        $clean = null;
        try {
            $field = null;
            // The page may be a translation's; the forms are the product's (design 51).
            $productId = $this->translations->original($command->productId);
            // A shop that takes no orders has no use for files: taking them would only store strangers' files.
            if (!$this->settings->current()->catalogOnly && $this->products->isPublished($productId)) {
                foreach ($this->forms->productFields($productId, $this->products->termIds($productId)['categories']) as $asked) {
                    if ($asked->id === $command->fieldId && $asked->kind === 'file') {
                        $field = $asked;
                    }
                }
            }
            if ($field === null) {
                throw self::refused('This product asks for no such file.');
            }
            $size = filesize($command->temporaryPath);
            if ($size === false || $size === 0) {
                throw self::refused('Choose a file.');
            }
            if ($size > $field->largest * 1024 * 1024) {
                throw self::refused('The file is larger than %d MB.', $field->largest);
            }
            $sender = self::sender($command->address);
            if ($this->uploads->sentBy($sender, $this->clock->now()->modify('-1 day')) + $size > self::MAX_SENT_A_DAY) {
                throw self::refused('That is as many files as one visitor may send in a day. Try again tomorrow.');
            }
            if ($this->uploads->untakenBytes() + $size > self::MAX_UNTAKEN_BYTES || $this->uploads->unorderedBytes() + $size > self::MAX_UNORDERED_BYTES) {
                throw self::refused('Files cannot be sent right now. Try again later.');
            }
            $type = $this->inspector->type($command->temporaryPath);
            $takes = $this->inspector->takesImages() ? $field->types : array_values(array_filter($field->types, static fn(string $type): bool => !str_starts_with($type, 'image/')));
            if ($type === null || !\in_array($type, $takes, true)) {
                throw self::refused('This field takes %s files.', implode(', ', array_map(static fn(string $type): string => strtoupper(self::EXTENSIONS[$type] ?? $type), $takes)));
            }
            try {
                $clean = $this->inspector->clean($command->temporaryPath, $type);
            } catch (FormRuleViolation $e) {
                throw self::refused($e->text);
            }
            $size = (int) filesize($clean);
            // A picture written again may come out larger; not by much more than it was allowed.
            if ($size > 2 * $field->largest * 1024 * 1024) {
                throw self::refused('The file is larger than %d MB.', $field->largest);
            }
            $name = CleanText::filename($command->filename);
            $name = (string) preg_replace('/\.[^.]*$/', '', $name);
            $name = ($name === '' ? 'file' : $name) . '.' . self::EXTENSIONS[$type];
            $stored = $this->files->store($clean, $name);
            $clean = null;
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $upload = new FormUpload($token, $stored->key, $name, $type, $size, $this->clock->now(), $sender);
            $this->uploads->save($upload);

            return new SentUpload($token, $upload->filename(), $type, $size);
        } finally {
            foreach ([$command->temporaryPath, $clean] as $path) {
                if ($path !== null && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * A placed order takes the files its lines name. One another order took first stays that order's; the shop keeps
     * one file per answer, and a cart is placed once.
     *
     * @param list<int> $uploadIds
     */
    public function take(int $orderId, array $uploadIds): void
    {
        foreach (array_unique($uploadIds) as $id) {
            $upload = $this->uploads->find($id);
            if ($upload !== null && $upload->orderId() === null) {
                $upload->takenBy($orderId);
                $this->uploads->save($upload);
            }
        }
    }

    /**
     * A cart holds the files its lines name, so they last as long as it does.
     *
     * @param list<int> $uploadIds
     */
    public function hold(array $uploadIds): void
    {
        foreach (array_unique($uploadIds) as $id) {
            $upload = $this->uploads->find($id);
            if ($upload !== null && $upload->orderId() === null) {
                $upload->hold($this->clock->now());
                $this->uploads->save($upload);
            }
        }
    }

    /**
     * The file for the order screen: its path, name and type; null when it is gone or not the order's.
     *
     * @return array{path: string, name: string, type: string}|null
     */
    public function file(int $orderId, int $uploadId): ?array
    {
        $upload = $this->uploads->find($uploadId);
        $path = $upload !== null && $upload->orderId() === $orderId ? $this->files->path($upload->storageKey()) : null;

        return $upload === null || $path === null ? null : ['path' => $path, 'name' => $upload->filename(), 'type' => $upload->mimeType()];
    }

    /** Deletes an order's files, as when it is deleted or anonymised. */
    public function forgetOrder(int $orderId): void
    {
        foreach ($this->uploads->ofOrder($orderId) as $upload) {
            $this->delete($upload);
        }
    }

    /**
     * Deletes uploads no order took once they are old, a batch at a time.
     *
     * @return int how many
     */
    public function forgetUntaken(int $limit = 200): int
    {
        $now = $this->clock->now();
        $old = $this->uploads->untakenBefore($now->modify(self::KEPT_FOR), $now->modify(self::HELD_FOR), $limit);
        foreach ($old as $upload) {
            $this->delete($upload);
        }

        return \count($old);
    }

    private function delete(FormUpload $upload): void
    {
        $this->files->delete($upload->storageKey());
        $this->uploads->remove($upload);
    }

    /** The sender's address as a hash: an IPv6 address by its /64 network, which one machine can fill. */
    private static function sender(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed !== false && \strlen($packed) === 16) {
            $address = bin2hex(substr($packed, 0, 8)) . '::/64';
        }

        return hash('sha256', 'orillacart-upload-sender|' . $address);
    }

    private static function refused(string|Text $message, int|string ...$values): InvalidFields
    {
        return new InvalidFields([['field' => 'file', 'message' => $message instanceof Text ? $message : Text::of($message, ...$values)]]);
    }
}
