<?php

declare(strict_types=1);

namespace Rector\DowngradePhp80\Rector\Expression;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use Rector\Core\NodeManipulator\BinaryOpManipulator;
use Rector\Core\Rector\AbstractRector;
use Rector\NodeAnalyzer\CoalesceAnalyzer;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @changelog https://wiki.php.net/rfc/throw_expression
 *
 * @see \Rector\Tests\DowngradePhp80\Rector\Expression\DowngradeThrowExprRector\DowngradeThrowExprRectorTest
 */
final class DowngradeThrowExprRector extends AbstractRector
{
    public function __construct(
        private readonly CoalesceAnalyzer $coalesceAnalyzer,
        private readonly BinaryOpManipulator $binaryOpManipulator,
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Downgrade throw expression', [
            new CodeSample(
                <<<'CODE_SAMPLE'
echo $variable ?? throw new RuntimeException();
CODE_SAMPLE

                ,
                <<<'CODE_SAMPLE'
if (! isset($variable)) {
    throw new RuntimeException();
}

echo $variable;
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Expression::class, Return_::class];
    }

    /**
     * @param Expression|Return_ $node
     * @return Node|Node[]|null
     */
    public function refactor(Node $node): Node|array|null
    {
        if ($node instanceof Return_) {
            return $this->refactorReturn($node);
        }

        if ($node->expr instanceof Throw_) {
            return null;
        }

        if ($node->expr instanceof Assign) {
            $resultNode = $this->refactorAssign($node->expr);
            if ($resultNode !== null) {
                return $resultNode;
            }
        }

        if ($node->expr instanceof Coalesce) {
            return $this->refactorCoalesce($node->expr, null);
        }

        if ($node->expr instanceof Ternary) {
            return $this->refactorTernary($node->expr, null);
        }

        return $this->refactorDirectCoalesce($node);
    }

    /**
     * @return If_|Expression|Stmt[]|null
     */
    private function refactorAssign(Assign $assign): If_ | Expression | null | array
    {
        if (! $this->hasThrowInAssignExpr($assign)) {
            return null;
        }

        if ($assign->expr instanceof Coalesce) {
            return $this->refactorCoalesce($assign->expr, $assign);
        }

        if ($assign->expr instanceof Throw_) {
            return new Expression(($assign->expr));
        }

        if ($assign->expr instanceof Ternary) {
            return $this->refactorTernary($assign->expr, $assign);
        }

        return null;
    }

    /**
     * @return If_|Stmt[]|null
     */
    private function refactorTernary(Ternary $ternary, ?Assign $assign): If_|null|array
    {
        if (! $ternary->else instanceof Throw_) {
            return null;
        }

        $inversedTernaryExpr = $this->binaryOpManipulator->inverseNode($ternary->cond);

        $if = new If_($inversedTernaryExpr, [
            'stmts' => [new Expression($ternary->else)],
        ]);

        if (! $assign instanceof Assign) {
            return $if;
        }

        $assign->expr = $ternary->if ?? $ternary->cond;

        return [$if, new Expression($assign)];
    }

    /**
     * @return If_|Stmt[]|null
     */
    private function refactorCoalesce(Coalesce $coalesce, ?Assign $assign): If_|null|array
    {
        if (! $coalesce->right instanceof Throw_) {
            return null;
        }

        if (! $this->coalesceAnalyzer->hasIssetableLeft($coalesce)) {
            return null;
        }

        $condExpr = $this->createCondExpr($coalesce);
        $if = new If_($condExpr, [
            'stmts' => [new Expression($coalesce->right)],
        ]);

        if (! $assign instanceof Assign) {
            return $if;
        }

        $assign->expr = $coalesce->left;

        return [$if, new Expression($assign)];
    }

    private function hasThrowInAssignExpr(Assign $assign): bool
    {
        return (bool) $this->betterNodeFinder->findFirst(
            $assign->expr,
            static fn (Node $node): bool => $node instanceof Throw_
        );
    }

    /**
     * @return Node[]|null
     */
    private function refactorReturn(Return_ $return): ?array
    {
        $throwExpr = $this->betterNodeFinder->findFirstInstanceOf($return, Throw_::class);
        if (! $throwExpr instanceof Throw_) {
            return null;
        }

        if ($return->expr instanceof Coalesce) {
            $coalesce = $return->expr;
            if (! $coalesce->right instanceof Throw_) {
                return null;
            }

            $if = $this->createIf($coalesce, $coalesce->right);
            return [$if, new Return_($coalesce->left)];
        }

        if ($return->expr instanceof Throw_) {
            return [new Expression($return->expr)];
        }

        return null;
    }

    private function createIf(Coalesce $coalesce, Throw_ $throw): If_
    {
        $conditionalExpr = $coalesce->left;

        if ($conditionalExpr instanceof Variable || $conditionalExpr instanceof ArrayDimFetch || $conditionalExpr instanceof PropertyFetch) {
            $booleanNot = new BooleanNot(new Isset_([$conditionalExpr]));
        } else {
            $booleanNot = new NotIdentical($conditionalExpr, new ConstFetch(new Name('null')));
        }

        return new If_($booleanNot, [
            'stmts' => [new Expression($throw)],
        ]);
    }

    private function createCondExpr(Coalesce $coalesce): BooleanNot|Identical
    {
        if ($coalesce->left instanceof Variable || $coalesce->left instanceof ArrayDimFetch) {
            return new BooleanNot(new Isset_([$coalesce->left]));
        }

        return new Identical($coalesce->left, $this->nodeFactory->createNull());
    }

    /**
     * @return Stmt[]|null
     */
    private function refactorDirectCoalesce(Expression $expression): ?array
    {
        /** @var Coalesce[] $coalesces */
        $coalesces = $this->betterNodeFinder->findInstanceOf($expression, Coalesce::class);

        foreach ($coalesces as $coalesce) {
            if (! $coalesce->right instanceof Throw_) {
                continue;
            }

            // add condition if above
            $throwExpr = $coalesce->right;
            $throw = new Stmt\Throw_($throwExpr->expr);

            $if = new If_(new Identical($coalesce->left, new ConstFetch(new Name('null'))), [
                'stmts' => [$throw],
            ]);

            // replace coalsese with left :)
            $this->traverseNodesWithCallable($expression, static function (Node $node): ?Expr {
                if (! $node instanceof Coalesce) {
                    return null;
                }

                return $node->left;
            });

            return [$if, $expression];
        }

        return null;
    }
}
