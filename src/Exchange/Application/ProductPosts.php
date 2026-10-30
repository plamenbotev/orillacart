<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Application\Dto\ProductPost;
use OrillaCart\Exchange\Application\Dto\ProductPostChange;

/**
 * Products as WordPress keeps them: the post, its texts and its terms.
 */
interface ProductPosts
{
    /**
     * @param list<int> $ids
     *
     * @return array<int, ProductPost> by id; a product without its post is left out
     */
    public function of(array $ids): array;

    /**
     * A new product's post, for a row the import adds.
     *
     * @return int the product's id
     */
    public function create(string $name, string $status): int;

    /** Changes what the change names; the rest stays. Categories missing are made, with their parents. */
    public function change(int $id, ProductPostChange $change): void;

    /** Deletes a post the import made for a row it then refused. */
    public function delete(int $id): void;

    /**
     * Runs the work as the user, and as who ran before after it: WordPress writes posts as theirs (their name as
     * the author, the HTML they may use). Nobody (null) runs it as no user.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function asUser(?int $userId, \Closure $work): mixed;
}
