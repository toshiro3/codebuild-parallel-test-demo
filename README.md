# CodeBuild Batch で PHPUnit を並列実行する検証

CodeBuild の Batch ビルド機能を使って、PHPUnit のテスト実行時間を短縮できるか検証するプロジェクト。

## 構成

```
.
├── buildspec.yml              # 通常版（直列実行）
├── buildspec-parallel.yml     # 並列版: build-list + testsuite 方式
├── buildspec-fanout.yml       # 並列版: build-fanout + ラッパースクリプト方式
├── phpunit.xml                # testsuite 定義（静的分割用）
├── phpunit.template.xml       # 動的生成用テンプレート
├── run_parallel_phpunit.sh    # PHPUnit実行ラッパースクリプト
├── docker-compose.yml         # ローカル動作確認用
└── tests/
    └── Unit/
        ├── UserServiceTest.php
        ├── OrderServiceTest.php
        ├── PaymentServiceTest.php
        ├── NotificationServiceTest.php
        ├── InventoryServiceTest.php
        └── ReportServiceTest.php
```

## 並列化の方式

### 方式1: build-list + testsuite（静的分割）

`phpunit.xml` に testsuite を事前定義し、各シャードで実行する方式。

```yaml
# buildspec-parallel.yml
batch:
  build-list:
    - identifier: shard_1
      env:
        variables:
          SHARD_NUM: "1"
    - identifier: shard_2
      env:
        variables:
          SHARD_NUM: "2"
```

シンプルだが、テストファイル追加時に `phpunit.xml` のメンテナンスが必要。

### 方式2: build-fanout + ラッパースクリプト（動的分割）

`codebuild-tests-run` でファイルを動的分割し、ラッパースクリプトで `phpunit.xml` を生成する方式。

```yaml
# buildspec-fanout.yml
batch:
  build-fanout:
    parallelism: 3

phases:
  build:
    commands:
      - |
        codebuild-tests-run \
          --test-command "./run_parallel_phpunit.sh" \
          --files-search "codebuild-glob-search 'tests/**/*Test.php'"
```

PHPUnit は複数ファイルを引数に取れないため、ラッパースクリプトで動的に `phpunit.xml` を生成して回避する。

## ローカルでの動作確認

```bash
# 依存インストール
docker compose run --rm composer

# 全テスト実行（約43秒）
time docker compose run --rm php

# シャード別実行
time docker compose run --rm php vendor/bin/phpunit --testsuite shard-1
```

## CodeBuild での実行（AWS CLI）

### IAMロールの作成

```bash
# 信頼ポリシーを作成
cat > trust-policy.json << 'EOF'
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Service": "codebuild.amazonaws.com"
      },
      "Action": "sts:AssumeRole"
    }
  ]
}
EOF

# IAMロールを作成
aws iam create-role \
  --role-name codebuild-parallel-test-role \
  --assume-role-policy-document file://trust-policy.json

# 権限ポリシーを作成
cat > codebuild-policy.json << 'EOF'
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "logs:CreateLogGroup",
        "logs:CreateLogStream",
        "logs:PutLogEvents"
      ],
      "Resource": "*"
    },
    {
      "Effect": "Allow",
      "Action": [
        "codebuild:StartBuild",
        "codebuild:StopBuild",
        "codebuild:BatchGetBuilds"
      ],
      "Resource": "*"
    }
  ]
}
EOF

# ポリシーをアタッチ
aws iam put-role-policy \
  --role-name codebuild-parallel-test-role \
  --policy-name codebuild-parallel-test-policy \
  --policy-document file://codebuild-policy.json
```

### CodeBuildプロジェクトの作成

```bash
AWS_ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)

aws codebuild create-project \
  --name codebuild-parallel-test-fanout \
  --source '{
    "type": "GITHUB",
    "location": "https://github.com/<your-username>/codebuild-parallel-test-demo",
    "buildspec": "buildspec-fanout.yml"
  }' \
  --artifacts '{"type": "NO_ARTIFACTS"}' \
  --environment '{
    "type": "LINUX_CONTAINER",
    "image": "aws/codebuild/amazonlinux-x86_64-standard:5.0",
    "computeType": "BUILD_GENERAL1_SMALL"
  }' \
  --service-role "arn:aws:iam::${AWS_ACCOUNT_ID}:role/codebuild-parallel-test-role"
```

### バッチビルドの実行

```bash
aws codebuild start-build-batch \
  --project-name codebuild-parallel-test-fanout
```

### 後片付け

```bash
# プロジェクト削除
aws codebuild delete-project --name codebuild-parallel-test-fanout

# IAMロール削除
aws iam delete-role-policy \
  --role-name codebuild-parallel-test-role \
  --policy-name codebuild-parallel-test-policy

aws iam delete-role --role-name codebuild-parallel-test-role
```

## 注意点

### セットアップコストの考慮

各シャードで独立して `composer install` が実行されるため、テスト時間が短い場合はセットアップコストで並列化のメリットが相殺される可能性がある。

### post_build の挙動

`post_build` は全シャードで実行されるため、通知処理などは条件分岐が必要。

```yaml
post_build:
  commands:
    - |
      if [ "${SHARD_NUM}" = "1" ]; then
        # 通知処理
      fi
```

## 参考リンク

- [AWS CodeBuild batch builds](https://docs.aws.amazon.com/codebuild/latest/userguide/batch-build.html)
- [codebuild-tests-run CLI](https://docs.aws.amazon.com/codebuild/latest/userguide/test-splitting.html)
