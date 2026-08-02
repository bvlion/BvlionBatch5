# BvlionBatch5

BvlionBatch5は、XServer上で動作する認証付きHTTP APIを提供するプロジェクトです。

## 提供する機能

- メールの取得・Slack通知・振り分け
- 記念日通知
- スマートフォンから実行する定型Slack通知

## 技術方針

- WebアプリケーションフレームワークにはSlim 4を使用します。
- データベースアクセスにはPDOを使用します。
- APIはHTTPリクエスト内で処理を完了する同期処理とします。
- `/health`エンドポイントは実装しません。
- 不要な抽象化、DIコンテナ、基底Repository、過剰なClean Architectureは導入しません。

本番実行環境と配置構成は[本番実行環境](docs/production-environment.md)を参照してください。

## 環境設定

`.env.example`を`.env`としてコピーし、実行環境に合わせて値を設定します。

```shell
cp .env.example .env
```

| 環境変数 | 用途 |
| --- | --- |
| `APP_TIMEZONE` | アプリケーションのタイムゾーン |
| `DB_HOST` | データベースのホスト |
| `DB_PORT` | データベースのポート |
| `DB_NAME` | データベース名 |
| `DB_USER` | データベースのユーザー名 |
| `DB_PASSWORD` | データベースのパスワード |
| `SLACK_BOT_TOKEN` | Slack Bot Token |
| `IMAP_HOST` | IMAPサーバーのホスト |
| `IMAP_PORT` | IMAPサーバーのポート |
| `IMAP_USERNAME` | IMAP接続のユーザー名 |
| `IMAP_PASSWORD` | IMAP接続のパスワード |
| `SCHEDULER_BEARER_TOKEN` | メール処理・記念日通知用Bearer Token |
| `OVERTIME_BEARER_TOKEN` | 定型Slack通知用Bearer Token |

すべての環境変数が必須です。本番値は`.env`または実行環境の環境変数で注入し、コミットしません。設定が未指定または空の場合は、秘密値を含めず、該当する環境変数名を示してリクエスト処理を失敗させます。

## API認証

認証対象ルートと使用するBearer Tokenは次のとおりです。

| ルート | 環境変数 |
| --- | --- |
| `POST /api/mail/process` | `SCHEDULER_BEARER_TOKEN` |
| `POST /api/dating/notify` | `SCHEDULER_BEARER_TOKEN` |
| `POST /api/overtime/notify` | `OVERTIME_BEARER_TOKEN` |

各ルートの実装時に`BearerTokenMiddleware`をルートミドルウェアとして登録し、`Authorization: Bearer <token>`ヘッダーを検証します。ヘッダーが未指定、形式不正、またはトークンが一致しない場合は、トークン値をレスポンスへ含めずHTTP 401を返します。

## ローカル実行

ローカルPCにはDockerのみ必要です。PHPとComposerをローカルPCへインストールする必要はありません。

appコンテナをビルドし、依存関係をインストールします。

```shell
docker compose build app
docker compose run --rm app composer install
```

Docker Composeでアプリケーションを起動します。

```shell
docker compose up app
```

ブラウザで`http://127.0.0.1:8080/undefined-route`へアクセスすると、JSON形式の404エラーが返ります。

アプリケーションを停止します。

```shell
docker compose down
```

構文確認、コーディング規約の確認、テストは次のコマンドで実行します。

```shell
docker compose run --rm app composer lint
docker compose run --rm app composer style
docker compose run --rm app composer test
```

Dockerはローカル開発でのみ使用します。本番環境はDocker化せず、XServerのPHP 8.5.5を使用します。詳細は[本番実行環境](docs/production-environment.md)を参照してください。

## 開発運用

- 1つのIssueにつき、1つのブランチと1つのPRを作成します。
- PRはユーザーの明示的な承認を得るまでマージしません。
- Issueに記載された範囲を最小差分で実装し、明示されていない横展開は行いません。

## Publicリポジトリの運用

- 本番値、個人情報、秘密情報をコミットしません。
- Issue、PR、テスト、fixture、ログ、SQLにも実データを含めません。
- 認証情報、Webhook、チャンネルID、メール内容、通知文面、データベースの本番値は、リポジトリ外の安全な場所で管理します。
- サンプルやテストには、実データから生成していない架空の値を使用します。
