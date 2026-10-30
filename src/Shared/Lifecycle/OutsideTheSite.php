<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Lifecycle;

/**
 * A lifecycle listener whose uninstall reaches beyond the site's own tables, files and options: the network-wide
 * cache folder, or the site's posts and terms, which WordPress drops with the site itself. Left out when one site
 * of a network is deleted, so the other sites keep what they share and the deleted one is not cleaned post by post.
 */
interface OutsideTheSite {}
