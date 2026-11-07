<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * MATCH_AGAINST(column, :words) in DQL: MySQL's full-text match in boolean mode, over a column with a FULLTEXT
 * index. Its value is the relevance, 0 when nothing matches, so a condition reads MATCH_AGAINST(…) > 0. The words
 * are as FullTextQuery writes them ("+ada* +lovelace*").
 */
final class MatchAgainst extends FunctionNode
{
    public const string NAME = 'MATCH_AGAINST';

    private Node $column;
    private Node $words;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->column = $parser->StateFieldPathExpression();
        $parser->match(TokenType::T_COMMA);
        $this->words = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf('MATCH (%s) AGAINST (%s IN BOOLEAN MODE)', $this->column->dispatch($sqlWalker), $this->words->dispatch($sqlWalker));
    }
}
