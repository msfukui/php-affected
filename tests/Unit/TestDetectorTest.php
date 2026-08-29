<?php

declare(strict_types=1);

describe('テストコードかどうかの判定', function () {
    it('テスト用ディレクトリ配下をテストコードとみなす', function () {
        $root = makeProject([
            'tests/Fixtures/sample.php' => '<?php $x = 1;',
            'src/Service.php' => '<?php class Service {}',
        ]);
        [, , $detector] = analyzeProject($root);

        expect($detector->isTest($root . '/tests/Fixtures/sample.php'))->toBeTrue()
            ->and($detector->isTest($root . '/src/Service.php'))->toBeFalse();
    });

    it('ディレクトリの外でも TestCase を継承していればテストとみなす', function () {
        $root = makeProject([
            'app/CheckoutSuite.php' => <<<'PHP'
                <?php
                use PHPUnit\Framework\TestCase;
                final class CheckoutSuite extends TestCase {}
                PHP,
        ]);
        [, , $detector] = analyzeProject($root);

        expect($detector->isTest($root . '/app/CheckoutSuite.php'))->toBeTrue();
    });

    it('プロジェクト内の中間基底クラスを辿る', function () {
        $root = makeProject([
            'app/Base.php' => <<<'PHP'
                <?php
                use PHPUnit\Framework\TestCase;
                abstract class Base extends TestCase {}
                PHP,
            'app/Middle.php' => '<?php abstract class Middle extends Base {}',
            'app/Leaf.php' => '<?php final class Leaf extends Middle {}',
        ]);
        [, , $detector] = analyzeProject($root);

        expect($detector->isTest($root . '/app/Leaf.php'))->toBeTrue();
    });

    // Laravel の ChainedBatchTruthTest のように、名前だけ紛らわしい本番クラスがある
    it('名前が紛らわしくても本番コードから参照されていればテストとみなさない', function () {
        $root = makeProject([
            'src/TruthTest.php' => '<?php namespace App; class TruthTest {}',
            'src/Consumer.php' => '<?php namespace App; class Consumer { public function f(TruthTest $t) {} }',
        ]);
        [, , $detector] = analyzeProject($root);

        expect($detector->isTest($root . '/src/TruthTest.php'))->toBeFalse();
    });

    it('誰からも参照されていなければ名前規約だけでテストとみなす', function () {
        $root = makeProject(['src/LonelyTest.php' => '<?php namespace App; class LonelyTest {}']);
        [, , $detector] = analyzeProject($root);

        expect($detector->isTest($root . '/src/LonelyTest.php'))->toBeTrue();
    });

    it('bootstrap はテスト用ディレクトリにあってもテストではない', function () {
        $root = makeProject(['tests/bootstrap.php' => '<?php']);
        [, , $detector] = analyzeProject($root, [$root . '/tests/bootstrap.php']);

        expect($detector->isTest($root . '/tests/bootstrap.php'))->toBeFalse();
    });
});

describe('実行対象かどうかの判定', function () {
    beforeEach(function () {
        $this->root = makeProject([
            'tests/OrderTest.php' => <<<'PHP'
                <?php
                namespace App\Tests;
                use PHPUnit\Framework\TestCase;
                final class OrderTest extends TestCase {}
                PHP,
            'tests/Support/BaseTestCase.php' => <<<'PHP'
                <?php
                namespace App\Tests\Support;
                use PHPUnit\Framework\TestCase;
                abstract class BaseTestCase extends TestCase {}
                PHP,
            'tests/Support/Helpers.php' => '<?php namespace App\Tests\Support; trait Helpers {}',
            'tests/Fixtures/config.php' => '<?php return [];',
            'tests/pest_style_test.php' => '<?php test("works", fn () => null);',
        ]);
        [, , $this->detector] = analyzeProject($this->root);
    });

    it('TestCase を継承した具象クラスは実行対象', function () {
        expect($this->detector->isRunnableTest($this->root . '/tests/OrderTest.php'))->toBeTrue();
    });

    it('abstract な基底クラスは実行対象にしない', function () {
        expect($this->detector->isRunnableTest($this->root . '/tests/Support/BaseTestCase.php'))->toBeFalse();
    });

    it('trait は実行対象にしない', function () {
        expect($this->detector->isRunnableTest($this->root . '/tests/Support/Helpers.php'))->toBeFalse();
    });

    it('名前規約に合わないフィクスチャは実行対象にしない', function () {
        expect($this->detector->isRunnableTest($this->root . '/tests/Fixtures/config.php'))->toBeFalse();
    });

    it('クラスを持たない形式でも名前規約に合えば実行対象', function () {
        expect($this->detector->isRunnableTest($this->root . '/tests/pest_style_test.php'))->toBeTrue();
    });
});

describe('命名規約による対応付け', function () {
    it('Foo.php に対応する FooTest.php を拾う', function () {
        $root = makeProject([
            'src/Invoice.php' => '<?php namespace App; class Invoice {}',
            'tests/InvoiceTest.php' => <<<'PHP'
                <?php
                namespace App\Tests;
                use PHPUnit\Framework\TestCase;
                final class InvoiceTest extends TestCase {}
                PHP,
            'tests/OtherTest.php' => <<<'PHP'
                <?php
                namespace App\Tests;
                use PHPUnit\Framework\TestCase;
                final class OtherTest extends TestCase {}
                PHP,
        ]);
        [$scanner, , $detector] = analyzeProject($root);

        expect($detector->pairByName([$root . '/src/Invoice.php'], $scanner->scan()))
            ->toBe([$root . '/tests/InvoiceTest.php']);
    });

    it('対応するテストがなければ空を返す', function () {
        $root = makeProject(['src/Invoice.php' => '<?php class Invoice {}']);
        [$scanner, , $detector] = analyzeProject($root);

        expect($detector->pairByName([$root . '/src/Invoice.php'], $scanner->scan()))->toBe([]);
    });
});
