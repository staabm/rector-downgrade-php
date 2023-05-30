<?php

declare(strict_types=1);

namespace Rector\DowngradePhp73\Rector\FuncCall;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Cast\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use Rector\Core\Contract\PhpParser\Node\StmtsAwareInterface;
use Rector\Core\Rector\AbstractRector;
use Rector\Naming\Naming\VariableNaming;
use Rector\NodeAnalyzer\StmtMatcher;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @changelog https://wiki.php.net/rfc/array_key_first_last
 *
 * @see \Rector\Tests\DowngradePhp73\Rector\FuncCall\DowngradeArrayKeyFirstLastRector\DowngradeArrayKeyFirstLastRectorTest
 */
final class DowngradeArrayKeyFirstLastRector extends AbstractRector
{
    public function __construct(
        private readonly VariableNaming $variableNaming,
        private readonly StmtMatcher $stmtMatcher,
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Downgrade array_key_first() and array_key_last() functions', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeClass
{
    public function run($items)
    {
        $firstItemKey = array_key_first($items);
    }
}
CODE_SAMPLE

                ,
                <<<'CODE_SAMPLE'
class SomeClass
{
    public function run($items)
    {
        reset($items);
        $firstItemKey = key($items);
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Expression::class, If_::class, ElseIf_::class, Else_::class];
    }

    /**
     * @param Expression|If_|Stmt\Else_|Stmt\ElseIf_ $node
     * @return Stmt[]|StmtsAwareInterface|null
     */
    public function refactor(Node $node): array|StmtsAwareInterface|null
    {
        if ($node instanceof If_ || $node instanceof ElseIf_) {
            $scopeStmt = [$node->cond, ...$node->stmts];
        } elseif ($node instanceof Else_) {
            $scopeStmt = $node->stmts;
        } else {
            $scopeStmt = $node;
        }

        $isPartOfCond = false;

        if ($node instanceof If_ || $node instanceof ElseIf_) {
            $isPartOfCond = $this->stmtMatcher->matchFuncCallNamed([$node->cond], 'array_key_first') ||
                $this->stmtMatcher->matchFuncCallNamed([$node->cond], 'array_key_last');
        }

        $funcCall = $this->stmtMatcher->matchFuncCallNamed($scopeStmt, 'array_key_first');
        if ($funcCall instanceof FuncCall) {
            return $this->refactorArrayKeyFirst($funcCall, $node, $isPartOfCond);
        }

        $funcCall = $this->stmtMatcher->matchFuncCallNamed($scopeStmt, 'array_key_last');
        if ($funcCall instanceof FuncCall) {
            return $this->refactorArrayKeyLast($funcCall, $node, $isPartOfCond);
        }

        return null;
    }

    /**
     * @return Stmt[]|StmtsAwareInterface|null
     */
    private function refactorArrayKeyFirst(
        FuncCall $funcCall,
        Expression|StmtsAwareInterface $stmt,
        bool $isPartOfCond
    ): null|array|StmtsAwareInterface {
        if (! isset($funcCall->getArgs()[0])) {
            return null;
        }

        $originalArray = $funcCall->getArgs()[0]
->value;
        $array = $this->resolveCastedArray($originalArray);

        $newStmts = [];

        if ($originalArray !== $array) {
            $newStmts[] = new Expression(new Assign($array, $originalArray));
        }

        $resetFuncCall = $this->nodeFactory->createFuncCall('reset', [$array]);
        $resetFuncCallExpression = new Expression($resetFuncCall);

        $funcCall->name = new Name('key');
        if ($originalArray !== $array) {
            $firstArg = $funcCall->getArgs()[0];
            $firstArg->value = $array;
        }

        if ($stmt instanceof StmtsAwareInterface && $isPartOfCond === false) {
            $stmt->stmts = array_merge([$resetFuncCallExpression], (array) $stmt->stmts);
            return $stmt;
        }

        $newStmts[] = $resetFuncCallExpression;
        $newStmts[] = $stmt;

        return $newStmts;
    }

    /**
     * @return Stmt[]|StmtsAwareInterface|null
     */
    private function refactorArrayKeyLast(
        FuncCall $funcCall,
        Expression|StmtsAwareInterface $stmt,
        bool $isPartOfCond
    ): null|array|StmtsAwareInterface {
        $firstArg = $funcCall->getArgs()[0] ?? null;
        if (! $firstArg instanceof Arg) {
            return null;
        }

        $originalArray = $firstArg->value;
        $array = $this->resolveCastedArray($originalArray);

        $newStmts = [];

        if ($originalArray !== $array) {
            $newStmts[] = new Expression(new Assign($array, $originalArray));
        }

        $endFuncCall = $this->nodeFactory->createFuncCall('end', [$array]);
        $endFuncCallExpression = new Expression($endFuncCall);
        $newStmts[] = $endFuncCallExpression;

        $funcCall->name = new Name('key');
        if ($originalArray !== $array) {
            $firstArg->value = $array;
        }

        if ($stmt instanceof StmtsAwareInterface && $isPartOfCond === false) {
            $stmt->stmts = array_merge([$endFuncCallExpression], (array) $stmt->stmts);
            return $stmt;
        }

        $newStmts[] = $stmt;

        return $newStmts;
    }

    private function resolveCastedArray(Expr $expr): Expr|Variable
    {
        if (! $expr instanceof Array_) {
            return $expr;
        }

        if ($expr->expr instanceof Array_) {
            return $this->resolveCastedArray($expr->expr);
        }

        $scope = $expr->getAttribute(AttributeKey::SCOPE);

        $variableName = $this->variableNaming->createCountedValueName(
            (string) $this->nodeNameResolver->getName($expr->expr),
            $scope
        );

        return new Variable($variableName);
    }
}
