<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

/**
 * The pictures the assistant may add to the media library: JPEG, PNG, GIF, WebP and AVIF, named so and being so.
 * Whatever else WordPress or another plugin lets the library take (SVG, which runs scripts where it is opened, or
 * an icon format) is not taken from the assistant.
 */
final class AssistantImages
{
    /** By the extensions they are named with, a "|" between them as WordPress lists them. */
    public const array TYPES = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    private const array IMAGE_TYPES = [
        'image/jpeg' => \IMAGETYPE_JPEG,
        'image/png' => \IMAGETYPE_PNG,
        'image/gif' => \IMAGETYPE_GIF,
        'image/webp' => \IMAGETYPE_WEBP,
        'image/avif' => \IMAGETYPE_AVIF,
    ];

    /**
     * The picture's type when its name's extension is one of TYPES and its content is a picture of that type, as
     * fileinfo reads it and as the picture's own header says; null otherwise.
     */
    public static function type(string $path, string $name): ?string
    {
        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
        $named = null;
        foreach (self::TYPES as $extensions => $type) {
            if ($extension !== '' && \in_array($extension, explode('|', $extensions), true)) {
                $named = $type;
            }
        }
        if ($named === null || !class_exists(\finfo::class)) {
            return null;
        }
        $read = new \finfo(\FILEINFO_MIME_TYPE)->file($path);
        $size = @getimagesize($path);
        if ($read !== $named || $size === false || $size[2] !== self::IMAGE_TYPES[$named] || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return $named;
    }
}
