<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../libs/EPMath.php';

final class EPMathTest extends TestCase
{
    public function testQuantile(): void
    {
        $this->assertEqualsWithDelta(3.0, EPMath::quantile([5, 1, 3, 2, 4], 0.5), 1e-9);
        $this->assertEqualsWithDelta(1.4, EPMath::quantile([1, 2, 3, 4, 5], 0.1), 1e-9);
        $this->assertSame(0.0, EPMath::quantile([], 0.5));
    }

    public function testWeightedQuantileEqualWeightsIsMedian(): void
    {
        $v = [10, 20, 30, 40, 50];
        $this->assertEqualsWithDelta(30.0, EPMath::weightedQuantile($v, [1, 1, 1, 1, 1], 0.5), 1e-9);
    }

    public function testWeightedQuantileFollowsWeights(): void
    {
        $v = [10, 20, 30];
        $this->assertEqualsWithDelta(10.0, EPMath::weightedQuantile($v, [10, 1, 1], 0.4), 1e-9);
        $this->assertEqualsWithDelta(30.0, EPMath::weightedQuantile($v, [1, 1, 10], 0.6), 1e-9);
        $lo = EPMath::weightedQuantile($v, [1, 1, 1], 0.1);
        $hi = EPMath::weightedQuantile($v, [1, 1, 1], 0.9);
        $this->assertTrue($lo <= 20.0 && $hi >= 20.0 && $lo < $hi);
    }

    public function testKernelWeights(): void
    {
        $w = EPMath::kernelWeights([0.0, 1.0, 2.0]);
        $this->assertEqualsWithDelta(1.0, $w[0], 1e-9);
        $this->assertTrue($w[0] > $w[1] && $w[1] > $w[2]);
    }

    public function testLeastSquaresExactFit(): void
    {
        $X = [];
        $y = [];
        foreach ([0, 1, 2, 3, 5, 8] as $x) {
            $X[] = [1.0, (float)$x];
            $y[] = 2.0 + 3.0 * $x;
        }
        $b = EPMath::leastSquares($X, $y);
        $this->assertEqualsWithDelta(2.0, $b[0], 1e-6);
        $this->assertEqualsWithDelta(3.0, $b[1], 1e-6);
    }

    public function testLeastSquaresSingular(): void
    {
        $this->assertNull(EPMath::leastSquares([[1.0, 0.0], [1.0, 0.0], [1.0, 0.0]], [1, 2, 3]));
        $this->assertNull(EPMath::leastSquares([[1.0, 2.0]], [1]));
    }
}
