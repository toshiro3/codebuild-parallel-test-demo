# CodeBuild Batch で PHPUnit を並列実行する検証

CodeBuild の Batch ビルド機能を使って、PHPUnit のテスト実行時間を短縮できるか検証するプロジェクト。

## 構成

```
.
├── buildspec.yml              # 直列実行（ベースライン）
├── buildspec-parallel.yml     # 並列: build-list + testsuite（静的分割）
├── buildspec-fanout.yml       # 並列: build-fanout + ラッパースクリプト（動的分割）
├── phpunit.xml                # testsuite 定義
├── phpunit.template.xml       # 動的生成用テンプレート
├── run_parallel_phpunit.sh    # ラッパースクリプト
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

`phpunit.xml` に testsuite を事前定義する方式。シンプルだが、テストファイル追加時にメンテナンスが必要。

### 方式2: build-fanout + ラッパースクリプト（動的分割）

`codebuild-tests-run` でファイルを動的分割し、ラッパースクリプトで `phpunit.xml` を生成する方式。
PHPUnit は複数ファイルを引数に取れないため、この回避策が必要。

## ローカルでの動作確認

```bash
# 依存インストール
docker compose run --rm composer

# 全テスト実行（約43秒）
time docker compose run --rm php
```

## 検証手順

### 1. 環境準備（IAMロール作成）

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

### 2. CodeBuildプロジェクトの作成

```bash
AWS_ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)

aws codebuild create-project \
  --name codebuild-parallel-test \
  --source '{
    "type": "GITHUB",
    "location": "https://github.com/<your-username>/codebuild-parallel-test-demo",
    "buildspec": "buildspec.yml"
  }' \
  --artifacts '{"type": "NO_ARTIFACTS"}' \
  --environment '{
    "type": "LINUX_CONTAINER",
    "image": "aws/codebuild/amazonlinux-x86_64-standard:5.0",
    "computeType": "BUILD_GENERAL1_SMALL"
  }' \
  --service-role "arn:aws:iam::${AWS_ACCOUNT_ID}:role/codebuild-parallel-test-role" \
  --build-batch-config '{
    "serviceRole": "arn:aws:iam::'"${AWS_ACCOUNT_ID}"':role/codebuild-parallel-test-role",
    "restrictions": {
      "maximumBuildsAllowed": 10
    }
  }'
```

### 3. 検証実行

#### 直列実行（ベースライン）

```bash
aws codebuild start-build \
  --project-name codebuild-parallel-test
```

#### 並列実行（build-list方式）

```bash
aws codebuild start-build-batch \
  --project-name codebuild-parallel-test \
  --buildspec-override "buildspec-parallel.yml"
```

#### 並列実行（build-fanout方式）

```bash
aws codebuild start-build-batch \
  --project-name codebuild-parallel-test \
  --buildspec-override "buildspec-fanout.yml"
```

### 4. 結果確認

```bash
# 直列ビルドの結果
aws codebuild list-builds-for-project \
  --project-name codebuild-parallel-test \
  --query 'ids[0]' --output text | \
xargs -I {} aws codebuild batch-get-builds --ids {} \
  --query 'builds[0].{status:buildStatus,duration:phases[?phaseType==`BUILD`].durationInSeconds|[0]}'

# バッチビルドの結果
aws codebuild list-build-batches-for-project \
  --project-name codebuild-parallel-test \
  --query 'ids[0]' --output text | \
xargs -I {} aws codebuild batch-get-build-batches --ids {} \
  --query 'buildBatches[0].{status:buildBatchStatus,duration:buildTimeInMinutes}'
```

### 5. クリーンアップ

```bash
# CodeBuild プロジェクトの削除
aws codebuild delete-project --name codebuild-parallel-test

# IAMロールの削除
aws iam delete-role-policy \
  --role-name codebuild-parallel-test-role \
  --policy-name codebuild-parallel-test-policy

aws iam delete-role --role-name codebuild-parallel-test-role

# 一時ファイルの削除
rm -f trust-policy.json codebuild-policy.json
```

## 注意点

### セットアップコストの考慮

各シャードで独立して `composer install` が実行されるため、テスト時間が短い場合はセットアップコストで並列化のメリットが相殺される可能性がある。

### post_build の挙動

`post_build` は全シャードで実行される。通知処理などを入れる場合は条件分岐が必要。

## 参考リンク

- [AWS CodeBuild batch builds](https://docs.aws.amazon.com/codebuild/latest/userguide/batch-build.html)
- [codebuild-tests-run CLI](https://docs.aws.amazon.com/codebuild/latest/userguide/test-splitting.html)
