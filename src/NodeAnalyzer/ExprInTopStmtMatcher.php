<?php

declare(strict_types=1);

namespace Rector\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\While_;
use PHPStan\Analyser\Scope;
use Rector\Core\Contract\PhpParser\Node\StmtsAwareInterface;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\NodeTypeResolver\Node\AttributeKey;

/**
 * To resolve Expr in top Stmt from early Expr attribute
 * so the usage can append code before the Stmt
 */
final class ExprInTopStmtMatcher
{
    public function __construct(
        private readonly BetterNodeFinder $betterNodeFinder,
    ) {
    }

    /**
     * @param callable(Node $node): bool $filter
     */
    public function match(StmtsAwareInterface|Switch_|Return_|Expression|Echo_ $stmt, callable $filter): null|Expr
    {
        if ($stmt instanceof Closure) {
            return null;
        }

        $nodes = [];
        if ($stmt instanceof Foreach_) {
            // keyVar can be null, so need to be filtered only Expr
            $nodes = array_filter([$stmt->expr, $stmt->keyVar, $stmt->valueVar]);
        }

        if ($stmt instanceof For_) {
            $nodes = $stmt->init;
            $nodes = array_merge($nodes, $stmt->cond);
            $nodes = array_merge($nodes, $stmt->loop);
        }

        if ($stmt instanceof If_ || $stmt instanceof While_ || $stmt instanceof Do_ || $stmt instanceof Switch_) {
            $nodes = [$stmt->cond];
        }

        if ($stmt instanceof Echo_) {
            $nodes = $stmt->exprs;
        }

        foreach ($nodes as $node) {
            $expr = $this->resolveExpr($stmt, $node, $filter);
            if ($expr instanceof Expr) {
                return $expr;
            }
        }

        $expr = $this->resolveFromChildCond($stmt, $filter);
        if ($expr instanceof Expr) {
            return $expr;
        }

        return $this->resolveOnReturnOrExpression($stmt, $filter);
    }

    /**
     * @param callable(Node $node): bool $filter
     */
    private function resolveOnReturnOrExpression(
        StmtsAwareInterface|Switch_|Return_|Expression|Echo_ $stmt,
        callable $filter
    ): ?Expr {
        if (! $stmt instanceof Return_ && ! $stmt instanceof Expression) {
            return null;
        }

        if (! $stmt->expr instanceof Expr) {
            return null;
        }

        return $this->resolveExpr($stmt, $stmt->expr, $filter);
    }

    /**
     * @param Expr[]|Expr $exprs
     * @param callable(Node $node): bool $filter
     */
    private function resolveExpr(
        StmtsAwareInterface|Switch_|Return_|Expression|Echo_ $stmt,
        array|Expr $exprs,
        callable $filter
    ): ?Expr {
        $node = $this->betterNodeFinder->findFirst($exprs, $filter);

        if (! $node instanceof Expr) {
            return null;
        }

        $stmtScope = $stmt->getAttribute(AttributeKey::SCOPE);
        $exprScope = $node->getAttribute(AttributeKey::SCOPE);

        if (! $stmtScope instanceof Scope || ! $exprScope instanceof Scope) {
            return null;
        }

        if ($stmtScope->getParentScope() === $exprScope->getParentScope()) {
            return $node;
        }

        return null;
    }

    /**
     * @param callable(Node $node): bool $filter
     */
    private function resolveFromChildCond(
        StmtsAwareInterface|Switch_|Return_|Expression|Echo_ $stmt,
        callable $filter
    ): null|Expr {
        if (! $stmt instanceof If_ && ! $stmt instanceof Switch_) {
            return null;
        }

        $stmts = $stmt instanceof If_
            ? $stmt->elseifs
            : $stmt->cases;

        foreach ($stmts as $stmt) {
            if (! $stmt->cond instanceof Expr) {
                continue;
            }

            $expr = $this->resolveExpr($stmt, $stmt->cond, $filter);
            if ($expr instanceof Expr) {
                return $expr;
            }
        }

        return null;
    }
}
