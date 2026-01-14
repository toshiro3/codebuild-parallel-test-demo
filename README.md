# CodeBuild 並列テスト実行 検証プロジェクト

CodeBuild の Batch ビルド機能（build-list）を使って、PHPUnit のテスト実行時間を短縮する検証用プロジェクト。

## なぜ build-fan-out + codebuild-tests-run を使わないのか？

CodeBuild には `build-fan-out` + `codebuild-tests-run` という動的テスト分割の仕組みがある。
Jest や pytest では便利に使えるが、PHPUnit では以下の理由で素直に使えない。

```bash
# codebuild-tests-run はファイルリストを返す
$ codebuild-tests-run --test-file-pattern 'tests/**/*Test.php'
tests/Unit/UserServiceTest.php
tests/Unit/OrderServiceTest.php

# Jest/pytest: そのまま引数に渡せる ✅
jest tests/Unit/UserServiceTest.js tests/Unit/OrderServiceTest.js

# PHPUnit: 複数ファイルを引数に取れない ❌
vendor/bin/phpunit tests/Unit/UserServiceTest.php tests/Unit/OrderServiceTest.php
# → 最初のファイルしか実行されない
```

`--filter` オプションで正規表現に変換すれば可能だが、ハック的になるため、
本プロジェクトでは `build-list` + `testsuite` による静的分割方式を採用している。

## 構成

```
.
├── composer.json
├── phpunit.xml              # testsuite 定義（シャード分割）
├── buildspec.yml            # 通常版（Before計測用）
├── buildspec-parallel.yml   # 並列版: build-list + testsuite 方式
├── docker-compose.yml       # ローカル動作確認用
└── tests/
    └── Unit/
        ├── UserServiceTest.php         (7秒)
        ├── OrderServiceTest.php         (7秒)
        ├── PaymentServiceTest.php       (7秒)  
        ├── NotificationServiceTest.php  (7秒)
        ├── InventoryServiceTest.php     (7秒)
        └── ReportServiceTest.php        (8秒)
```

## テスト所要時間（予測）

| 実行方式 | 所要時間 | 備考 |
|---------|---------|------|
| 通常（直列） | 約43秒 | 全テスト順次実行 |
| 並列（3シャード） | 約14-15秒 | 最も遅いシャードに依存 |

## シャード分割

```
shard-1: UserServiceTest + OrderServiceTest      → 約14秒
shard-2: PaymentServiceTest + NotificationServiceTest → 約14秒  
shard-3: InventoryServiceTest + ReportServiceTest → 約15秒
```

## 使い方

### Docker でテスト実行（推奨）

```bash
# 依存インストール
docker compose run --rm composer

# 全テスト実行（約43秒）
time docker compose run --rm php

# シャード別実行
time docker compose run --rm php vendor/bin/phpunit --testsuite shard-1
time docker compose run --rm php vendor/bin/phpunit --testsuite shard-2
time docker compose run --rm php vendor/bin/phpunit --testsuite shard-3
```

### ローカルでテスト実行（PHP/Composerがある場合）

```bash
# 依存インストール
composer install

# 全テスト実行
composer test

# シャード別実行
composer test:shard1
composer test:shard2
composer test:shard3
```

### CodeBuild での実行

1. **通常版（Before）**
   - buildspec: `buildspec.yml`
   - Batch build: 無効

2. **並列版（After）**
   - buildspec: `buildspec-parallel.yml`
   - Batch build: 有効
   - サービスロールに `codebuild:StartBuild` 権限が必要

## PHPUnit + CodeBuild 並列化の注意点

### 1. post_build が全シャードで実行される

```yaml
post_build:
  commands:
    # ❌ これだと3回通知される
    - aws sns publish --message "Build completed"
    
    # ✅ シャード番号で条件分岐
    - |
      if [ "${SHARD_NUM}" = "1" ]; then
        aws sns publish --message "Build completed"
      fi
```

### 2. PHPUnit は複数ファイルを引数に取れない

```bash
# ❌ これはできない（最初のファイルのみ実行される）
vendor/bin/phpunit tests/Unit/UserTest.php tests/Unit/OrderTest.php

# ✅ testsuite で分割
vendor/bin/phpunit --testsuite shard-1
```

このため、`codebuild-tests-run` による動的分割は PHPUnit では使いにくい。

### 3. コスト

- 並列ビルドは各シャードが独立したビルドとして課金
- 3並列 × 1分 = 3ビルド分のコスト
- ただしトータル時間短縮による開発効率向上とトレードオフ

## 参考リンク

- [AWS CodeBuild batch builds](https://docs.aws.amazon.com/codebuild/latest/userguide/batch-build.html)
- [codebuild-tests-run CLI](https://docs.aws.amazon.com/codebuild/latest/userguide/test-splitting.html) ※PHPUnitでは使いにくい
