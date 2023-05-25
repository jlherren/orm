<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\ParserResult;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Query\SqlWalker;
use RuntimeException;

use function array_diff;
use function array_keys;
use function assert;
use function count;
use function implode;
use function reset;
use function sprintf;

/**
 * Wraps the query in order to accurately count the root objects.
 *
 * Given a DQL like `SELECT u FROM User u` it will generate an SQL query like:
 * SELECT COUNT(*) (SELECT DISTINCT <id> FROM (<original SQL>))
 *
 * Works with composite keys but cannot deal with queries that have multiple
 * root entities (e.g. `SELECT f, b from Foo, Bar`)
 *
 * Note that the ORDER BY clause is not removed. Many SQL implementations (e.g. MySQL)
 * are able to cache subqueries. By keeping the ORDER BY clause intact, the limitSubQuery
 * that will most likely be executed next can be read from the native SQL cache.
 *
 * @psalm-import-type QueryComponent from Parser
 */
class CountOutputWalker extends SqlWalker
{
    private readonly AbstractPlatform $platform;
    private readonly ResultSetMapping $rsm;

    /**
     * {@inheritDoc}
     */
    public function __construct(Query $query, ParserResult $parserResult, array $queryComponents)
    {
        $this->platform = $query->getEntityManager()->getConnection()->getDatabasePlatform();
        $this->rsm      = $parserResult->getResultSetMapping();

        parent::__construct($query, $parserResult, $queryComponents);
    }

    public function walkSelectStatement(SelectStatement $selectStatement): string
    {
        if ($this->platform instanceof SQLServerPlatform) {
            $selectStatement->orderByClause = null;
        }

        $sql = parent::walkSelectStatement($selectStatement);

        if ($selectStatement->groupByClause) {
            return sprintf(
                'SELECT COUNT(*) AS dctrn_count FROM (%s) dctrn_table',
                $sql,
            );
        }

        // Find out the SQL alias of the identifier column of the root entity
        // It may be possible to make this work with multiple root entities but that
        // would probably require issuing multiple queries or doing a UNION SELECT
        // so for now, It's not supported.

        // Get the root entity and alias from the AST fromClause
        $from = $selectStatement->fromClause->identificationVariableDeclarations;
        if (count($from) > 1) {
            throw new RuntimeException('Cannot count query which selects two FROM components, cannot make distinction');
        }

        $fromRoot       = reset($from);
        $rootAlias      = $fromRoot->rangeVariableDeclaration->aliasIdentificationVariable;
        $rootClass      = $this->getMetadataForDqlAlias($rootAlias);
        $rootIdentifier = $rootClass->identifier;

        // For every identifier, find out the SQL alias by combing through the ResultSetMapping
        $sqlIdentifier = [];
        foreach ($rootIdentifier as $property) {
            if (isset($rootClass->fieldMappings[$property])) {
                foreach (array_keys($this->rsm->fieldMappings, $property, true) as $alias) {
                    if ($this->rsm->columnOwnerMap[$alias] === $rootAlias) {
                        $sqlIdentifier[$property] = $alias;
                    }
                }
            }

            if (isset($rootClass->associationMappings[$property])) {
                $association = $rootClass->associationMappings[$property];
                assert($association->isToOneOwningSide());
                $joinColumn = $association->joinColumns[0]['name'];

                foreach (array_keys($this->rsm->metaMappings, $joinColumn, true) as $alias) {
                    if ($this->rsm->columnOwnerMap[$alias] === $rootAlias) {
                        $sqlIdentifier[$property] = $alias;
                    }
                }
            }
        }

        if (count($rootIdentifier) !== count($sqlIdentifier)) {
            throw new RuntimeException(sprintf(
                'Not all identifier properties can be found in the ResultSetMapping: %s',
                implode(', ', array_diff($rootIdentifier, array_keys($sqlIdentifier))),
            ));
        }

        // Build the counter query
        return sprintf(
            'SELECT COUNT(*) AS dctrn_count FROM (SELECT DISTINCT %s FROM (%s) dctrn_result) dctrn_table',
            implode(', ', $sqlIdentifier),
            $sql,
        );
    }
}
