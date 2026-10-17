<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tags')]
class Tag
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id = 0;
}
