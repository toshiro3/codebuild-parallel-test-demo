# CodeBuild 並列テスト実行 検証プロジェクト

CodeBuild の並列実行機能（batch build-fan-out）を使って、PHPUnit のテスト実行時間を短縮する検証用プロジェクト。

## 構成

```
.
├── composer.json
├── phpunit.xml              # testsuite 定義（シャード分割）
├── buildspec.yml            # 通常版（Before計測用）
├── buildspec-parallel.yml   # 並列版: testsuite 方式
├── buildspec-parallel-filter.yml  # 並列版: --filter 方式
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

### ローカルでテスト実行

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
   - buildspec: `buildspec-parallel.yml` または `buildspec-parallel-filter.yml`
   - Batch build: 有効
   - Compute type: 各シャードで独立して指定

## PHPUnit + CodeBuild 並列化の注意点

### 1. post_build が全シャードで実行される

```yaml
post_build:
  commands:
    # ❌ これだと3回通知される
    - aws sns publish --message "Build completed"
    
    # ✅ シャード番号で条件分岐
    - |
      SHARD_INDEX=$(echo $CODEBUILD_BATCH_BUILD_IDENTIFIER | grep -oE '[0-9]+$')
      if [ "$SHARD_INDEX" = "0" ]; then
        aws sns publish --message "Build completed"
      fi
```

### 2. PHPUnit は複数ファイルを引数に取れない

```bash
# ❌ これはできない
vendor/bin/phpunit tests/Unit/UserTest.php tests/Unit/OrderTest.php

# ✅ 方式A: testsuite で分割
vendor/bin/phpunit --testsuite shard-1

# ✅ 方式B: --filter で絞り込み
vendor/bin/phpunit --filter "(UserServiceTest|OrderServiceTest)"
```

### 3. コスト

- 並列ビルドは各シャードが独立したビルドとして課金
- 3並列 × 1分 = 3ビルド分のコスト
- ただしトータル時間短縮による開発効率向上とトレードオフ

## 参考リンク

- [AWS CodeBuild batch builds](https://docs.aws.amazon.com/codebuild/latest/userguide/batch-build.html)
- [codebuild-tests-run CLI](https://docs.aws.amazon.com/codebuild/latest/userguide/test-splitting.html)
