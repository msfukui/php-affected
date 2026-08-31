# php-affected

[![CI](https://github.com/msfukui/php-affected/actions/workflows/ci.yml/badge.svg)](https://github.com/msfukui/php-affected/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/msfukui/php-affected)](https://packagist.org/packages/msfukui/php-affected)
[![License](https://img.shields.io/packagist/l/msfukui/php-affected)](LICENSE)

指定されたある PHP ファイル群に依存している PHP ファイルを列挙する

特徴:

- PHP 標準の `token_get_all` にのみ依存している
- 実行にあたり、特定のフレームワーク・テストランナー・ディレクトリ構成には依存しない
- 字句解析しかしないため、解析するソース自体の PHP バージョンは問わない
- 静的解析しかしないため、実行時にしかわからない依存は追うことができない

実行例:

```bash
composer require --dev msfukui/php-affected
vendor/bin/php-affected --tests $(git diff --name-only origin/main)
```

## 考え方

```
                 依存の向き →
  FooTest.php  ─────>  Foo.php  ─────>  Money.php
                 <───── 影響の向き
```

Money.php から依存の向きを逆に辿ることで、影響を受ける可能性のある Foo.php, FooTest.php を列挙する

変更したファイルに依存するテストだけを実行することで、テストの実行対象と時間を減らすことを目的に作成したため、判断がつかない場合は「依存あり」に倒している

## インストール

```bash
composer require --dev msfukui/php-affected
```

依存するパッケージがないため PHP があれば `bin/` と `src/` をコピーするだけでも動作する

```bash
git clone https://github.com/msfukui/php-affected.git tools/php-affected
tools/php-affected/bin/php-affected --help
```

## 使い方

```
php-affected [オプション] <ファイル> [<ファイル>...]

  --root=DIR    プロジェクトルート (既定: カレントディレクトリ)
  --tests       対象をテストファイルだけに絞る
  --entry=PATH  実行単位の入口を宣言し、影響を受けた入口だけを出力する (複数指定可)
  --why         選ばれた理由の連鎖を表示
  --why=PATH    PATH 1 つだけについて理由の連鎖を表示する
  --stats       統計を stderr に出力
  -h, --help    このヘルプ
```

**既定では影響を受けるプロジェクトルート配下の全 PHP ファイルを出力する**が、対象をテストファイルのみに絞るには `--tests` を指定する

`--stats` で出力する統計情報には、プロジェクト全体と指定されたファイルに関連する情報がある
指定されたファイルに関する統計情報は `--tests` を指定した場合にのみ出力する

```
$ bin/php-affected --stats
プロジェクト: ファイル 3043 件 / ファイル間の依存 24531 / 対象テスト 983 件

$ bin/php-affected --tests --stats src/Illuminate/Support/Str.php
プロジェクト: ファイル 3043 件 / ファイル間の依存 24531 / 対象テスト 983 件
影響: 指定 1 件 → 到達 2426 件 → 出力 983 件 (テスト全体の 100%)
```

実行例:

```bash
# 影響を受けるファイルを列挙する
bin/php-affected --root=/path/to/project src/Payment/Gateway.php

# テストだけに絞る
bin/php-affected --tests src/Payment/Gateway.php

# git の変更から取得する
bin/php-affected --tests $(git diff --name-only origin/main)

# なぜ影響を受けるのか依存関係を表示する
bin/php-affected --why src/Support/Money.php

# 影響を受けた実行単位 (アプリケーションの入口) を列挙する
bin/php-affected --entry=public/index.php --entry=bin/console $(git diff --name-only origin/main)

# テストファイル以外を取り出す
comm -23 <(bin/php-affected         src/Support/Money.php | sort) \
         <(bin/php-affected --tests src/Support/Money.php | sort)
```

### 選ばれた理由を確認する

`--why` は、指定されたファイルまでの経路と **依存の原因になったクラス、関数、定数など**を表示する。

```
$ bin/php-affected --why src/Support/helpers.php
tests/OrderTest.php
  └─ class App\Order\Order → src/Order/Order.php
      └─ class App\Support\Money → src/Support/Money.php
          └─ function App\Support\format_money(), const App\Support\CURRENCY → src/Support/helpers.php   ← 指定ファイル
```

「OrderTest は `App\Order\Order` を使っており、
それは `App\Support\Money` を使っており、
それは `format_money()` を呼んでいる」
と読む

require/include, 全テストが読み込むファイル(bootstrap 等)、命名規約による対応付けも理由として表示される

DI コンテナのように、型宣言だけでは実装クラスに辿り着けない構成にも対応する

```
$ bin/php-affected --why src/Payment/StripeGateway.php
tests/ContainerTest.php
  └─ require/include → container/services.php
      └─ class App\Payment\StripeGateway (文字列リテラル) → src/Payment/StripeGateway.php   ← 指定ファイル
tests/PaymentServiceTest.php
  └─ class App\Payment\PaymentService → src/Payment/PaymentService.php   ← 指定ファイルの interface の利用側
```

`StripeGateway` はコンテナに文字列で登録されているだけで、利用側の `PaymentService` は
interface しか型宣言していないが、依存関係あり、と判定して表示する

### 全テストが読み込むファイル

composer の `autoload.files` と phpunit.xml の `bootstrap` に指定されたファイルは、
コード上どこからも参照されていなくてもテストプロセスに読み込まれる
そのため、これらを依存として扱わないと検出漏れになる

設定ファイルはプロジェクトルート以外も探索し、**設定ファイルが置かれたディレクトリ配下の
テストにだけ効く**ものとして扱う
モノレポで 1 パッケージの `composer.json` を変更したときに、全パッケージのテストが
選ばれてしまうことを避けるため

```
monorepo/
├── composer.json                  autoload.files → 配下の全テストに効く
├── packages/alpha/
│   ├── composer.json              autoload.files → alpha のテストにだけ効く
│   └── tests/AlphaTest.php
└── packages/beta/
    ├── phpunit.xml                bootstrap → beta のテストにだけ効く
    └── tests/BetaTest.php
```

相対パスは設定ファイルの位置を基準に解決する
同じディレクトリに `phpunit.xml` と `phpunit.xml.dist` の両方があれば前者を優先する

複数の経路がある場合も代表の 1 本だけを表示する

### 1 ファイルだけ調べる

`--why=PATH` で、そのファイル 1 つについてだけ経路を表示することができる

```
$ bin/php-affected --why=tests/OrderTest.php src/Support/helpers.php
tests/OrderTest.php
  └─ class App\Order\Order → src/Order/Order.php
      └─ class App\Support\Money → src/Support/Money.php
          └─ function App\Support\format_money(), const App\Support\CURRENCY → src/Support/helpers.php   ← 指定ファイル
```

PATH には依存関係の中間ファイルも指定できる

```
$ bin/php-affected --why=src/Support/Money.php src/Support/helpers.php
src/Support/Money.php
  └─ function App\Support\format_money(), const App\Support\CURRENCY → src/Support/helpers.php   ← 指定ファイル
```

PATH に指定したファイルが元の指定されたファイルに依存していない場合は、標準出力は空になり
stderr にその旨を出す (終了コードは 0)。

```
$ bin/php-affected --why=tests/WidgetTest.php src/Support/helpers.php
tests/WidgetTest.php は指定されたファイルに依存していません。
```

### 実行単位への影響を調べる

テストではなく「この変更でどのアプリケーションが影響を受けるか」を知りたい場合は、
`--entry` で実行単位の入口を宣言する。**影響が届いた入口だけを出力する**。

```
$ bin/php-affected --entry=public/index.php --entry=bin/console --entry=bin/worker \
      $(git diff --name-only origin/main)
public/index.php
bin/console
```

モノレポやマルチサービス構成で、どのコンテナを再ビルドするか、どのスモークテストを
回すかを CI で判定するために使う。`--why` と併用すれば入口までの経路も表示する。

```
$ bin/php-affected --entry=bin/console --why src/Service/ReportService.php
bin/console
  └─ class App\Console\ImportCommand → src/Console/ImportCommand.php
      └─ class App\Service\ReportService → src/Service/ReportService.php   ← 指定ファイル
```

`--entry` は逆探索の結果を絞り込むだけで、依存グラフには手を加えない。
入口を「全テストが読み込むファイル」のような依存として扱うと、フレームワークの入口は
アプリのほぼ全体に到達するので、どの変更でも全テストが選ばれてしまうからである。
`--tests` の結果は `--entry` の有無で変わらず、出力するものが食い違うので同時には指定できない。

**「出力されなかった = 影響なし」とは判断できない。** 実行時にパスを組み立てて読み込む
ファイル (フレームワークのルート定義など) でグラフが切れていると、実際には影響があっても
入口まで到達しないため。出力された入口を対象に加える、という**加算方向の判断にだけ使う**。

切れている箇所が分かっている場合は、実行時に読み込まれるファイル自体を入口として
宣言すれば拾える。

```
$ bin/php-affected --entry=routes/web.php $(git diff --name-only origin/main)
routes/web.php
```

### PHPUnit と組み合わせる

PHPUnit は引数を 1 つしか取らないため、1 ファイルずつ回すか設定ファイルを組み立てる。

```bash
# 単純に 1 ファイルずつテストを実行する
for f in $(bin/php-affected --tests $(git diff --name-only origin/main)); do
    vendor/bin/phpunit "$f" || exit 1
done
```

```bash
# 複数ファイルのテスト実行を 1 プロセスで済ませたい場合は設定を組み立てて実行する
FILES=$(bin/php-affected --tests $(git diff --name-only origin/main))
[ -z "$FILES" ] && exit 0
{
    echo '<phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="affected">'
    echo "$FILES" | sed 's|.*|<file>&</file>|'
    echo '</testsuite></testsuites></phpunit>'
} > affected.xml
vendor/bin/phpunit -c affected.xml
```

## 制約事項

過小検出となる場合:

- 動的な `require $path` や `new $className` の依存は検出できない
- `call_user_func('Foo::bar')` のような文字列経由の呼び出しは検出できない
- クラス名を動的に組み立てている場合 (`'App\\' . $name`) は検出できない
- 名前空間区切りのない文字列 (`'User'`) はクラス名として扱わない
  - 設定値やメッセージと区別がつかず、過剰検出が大きくなりすぎるため

過剰検出となる場合:

- 依存関係が一切なくても `Foo.php` に対応する `FooTest.php`, `FooTestCase.php`, `FooSpec.php` があれば依存ありと判定する
  - テストの命名規約に従っている場合、念の為テストが対象クラスを参照している可能性が高いと判断する
  - ディレクトリパスの対応は判断条件には含まれず、ファイル名のみで判定している
- 名前空間区切りを含む文字列リテラルをクラス参照として扱い、依存ありと判定する
- 指定ファイルが実装する interface を利用しているファイルも依存ありと判定する
  - 影響が大きくなりすぎるため基底クラスにまでは遡らない

その他:

- 1 ファイルに複数の namespace 宣言がある場合、docblock の型解決はファイル全体の `use` を合算して行う
- `--entry` で宣言した入口は、静的に辿れる依存でしか到達を判定できない
  - 出力された入口は影響あり、と判断してよいが、出力されなかったことは影響がないことの保証にはならない

## ライセンス

MIT
