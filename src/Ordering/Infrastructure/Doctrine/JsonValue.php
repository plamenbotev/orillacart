<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * JSON_VALUE(column, 'path') in DQL: one value out of a JSON column, unquoted, as MySQL and MariaDB read it; NULL
 * where the column or the path has none. For the shop's own words an order keeps beside the customer's (design 51).
 */
final class JsonValue extends FunctionNode
{
    public const string NAME = 'JSON_VALUE';

    private Node $json;
    private Node $path;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->json = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->path = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf('JSON_UNQUOTE(JSON_EXTRACT(%s, %s))', $this->json->dispatch($sqlWalker), $this->path->dispatch($sqlWalker));
    }
}
