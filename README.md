# BvlionBatch5

旧環境で稼働しているバッチ処理を、XServer上の認証付きHTTP APIへ移行するプロジェクトです。

## 移行範囲

### 対象

- 旧BvlionBatch4のメール処理
- 旧BvlionBatch4の記念日通知
- 旧HomeServerの残業通知

### 対象外

- 旧BvlionBatch4の `/horoscope`
- 旧BvlionBatch4の `/speak-time`

対象外の機能はBvlionBatch5へ移行しません。移行範囲を変更する場合は、対象を明記したIssueで合意してから着手します。

## 技術方針

- WebアプリケーションフレームワークにはSlim 4を使用します。
- データベースアクセスにはPDOを使用します。
- APIはHTTPリクエスト内で処理を完了する同期処理とします。
- `/health`エンドポイントは実装しません。
- 不要な抽象化、DIコンテナ、基底Repository、過剰なClean Architectureは導入しません。

本番実行環境と配置構成は[本番実行環境](docs/production-environment.md)を参照してください。

## ローカル実行

依存関係をインストールします。

```shell
composer install
```

PHPの組み込みWebサーバーでアプリケーションを起動します。

```shell
php -S 127.0.0.1:8080 -t public public/index.php
```

別のターミナルから、任意の未定義ルートへアクセスするとJSON形式の404エラーが返ります。

```shell
curl -i http://127.0.0.1:8080/undefined-route
```

構文確認、コーディング規約の確認、テストは次のコマンドで実行します。

```shell
composer lint
composer style
composer test
```

## 開発運用

- 1つのIssueにつき、1つのブランチと1つのPRを作成します。
- PRはユーザーの明示的な承認を得るまでマージしません。
- Issueに記載された範囲を最小差分で実装し、明示されていない横展開は行いません。

## Publicリポジトリの運用

- 本番値、個人情報、秘密情報をコミットしません。
- Issue、PR、テスト、fixture、ログ、SQLにも実データを含めません。
- 認証情報、Webhook、チャンネルID、メール内容、通知文面、データベースの本番値は、リポジトリ外の安全な場所で管理します。
- サンプルやテストには、実データから生成していない架空の値を使用します。
