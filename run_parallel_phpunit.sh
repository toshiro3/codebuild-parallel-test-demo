#!/bin/bash

# CodeBuildから渡された引数（ファイルパスのリスト）を取得
FILES=$@

echo "=== Received test files ==="
echo "$FILES"
echo "==========================="

# ファイルが空の場合は終了
if [ -z "$FILES" ]; then
    echo "No test files assigned to this shard"
    exit 0
fi

# 各ファイルパスを <file>path/to/Test.php</file> 形式に変換
FORMATTED_FILES=$(echo "$FILES" | sed 's|^|<file>|; s| |</file><file>|g; s|$|</file>|')

echo "=== Formatted files ==="
echo "$FORMATTED_FILES"
echo "======================="

# テンプレートのプレースホルダーを置換して一時ファイルを作成
sed "s@{{TEST_FILES}}@$FORMATTED_FILES@g" phpunit.template.xml > phpunit.dynamic.xml

echo "=== Generated phpunit.dynamic.xml ==="
cat phpunit.dynamic.xml
echo "======================================"

# 生成されたXMLを指定してPHPUnitを実行
./vendor/bin/phpunit -c phpunit.dynamic.xml
