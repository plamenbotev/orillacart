<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/**
 * Where an extension's scripts and stylesheets are served from (design 59): files the web server hands out
 * itself, under a URL that changes with the content, so browsers cache them for long.
 */
interface AssetFiles
{
    /**
     * Makes the file available at a public URL, if it is not already, and answers the URL. The name carries a
     * hash of the content: a changed file is a new URL, and older ones of the same name go.
     *
     * @param string $name the file's name under assets/, folders included: "portal.js"
     */
    public function publish(string $extension, string $name, string $content): string;

    /** The URL the extension's files are served under, ending in a slash: what a frame's policy lets load (design 73). */
    public function folderUrl(string $extension): string;

    /** Removes everything published for the extension. */
    public function forget(string $extension): void;
}
