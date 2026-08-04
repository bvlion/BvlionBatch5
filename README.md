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

## データベースとマイグレーション

ローカル開発ではMySQL 5.7コンテナを使用します。データベースを起動し、未適用のマイグレーションを適用します。

```shell
docker compose up --detach database
docker compose run --rm app composer migrate
```

マイグレーションSQLは`database/migrations`へ`YYYYMMDDHHMMSS_description.sql`形式で配置します。ファイル名の昇順で適用し、適用済みファイル名を`schema_migrations`テーブルへ記録するため、同じコマンドを再実行できます。

本番環境では、環境変数と依存関係を設定した後、XServerのPHP 8.5.5を明示して適用します。

```shell
/opt/php-8.5.5/bin/php bin/migrate.php
```

本番データ、データベースダンプ、個人情報、秘密情報はリポジトリへ配置しません。`dating`、`mail_api`、設定、メール処理履歴の各テーブルは、対応する後続Issueでマイグレーションを追加します。

## Slack App設定

本番Slack Appは、次の手順で設定します。

1. Slack Appの「Basic Information」で、Botの表示名とアイコンを設定します。
2. 「OAuth & Permissions」のBot Token Scopesへ`chat:write`を追加します。`chat:write.customize`は追加しません。
3. Slack Appを対象のワークスペースへインストールまたは再インストールします。
4. 通知先とする各チャンネルへSlack Appを追加します。
5. Bot Tokenを本番環境の`SLACK_BOT_TOKEN`へ設定します。
6. 通知先はチャンネルIDで管理し、実際のチャンネル名、チャンネルID、Bot Tokenをリポジトリや共有ログへ記録しません。

投稿にはSlack Web APIの[`chat.postMessage`](https://docs.slack.dev/reference/methods/chat.postMessage)を使用します。表示名、アイコン、チャンネル名はリクエストで上書きしません。

実チャンネルへの疎通確認には`SLACK_BOT_TOKEN`と`SLACK_TEST_CHANNEL_ID`だけを使用します。DB、IMAP、Bearer Tokenなど、アプリ本体の他の環境変数は不要です。

ローカルでは、Git管理対象外の`.env`へ2つの値だけを設定し、Docker経由で実行します。次の値は実データから生成していない架空値のため、実行時は実際のBot Tokenと確認先チャンネルIDへ置き換えます。

```dotenv
SLACK_BOT_TOKEN=xoxb-example-bot-token
SLACK_TEST_CHANNEL_ID=C0000000000
```

```shell
docker compose run --rm --no-deps app php bin/check-slack.php
```

本番環境では、`.env`または実行環境へ同じ2つの環境変数を設定し、XServerのPHP 8.5.5を明示して実行します。

```shell
/opt/php-8.5.5/bin/php bin/check-slack.php
```

このコマンドはテスト用文面`Slack API connectivity test.`を1件投稿します。成功時はToken、チャンネルID、メッセージの`ts`を出力せず、`Slack connectivity check succeeded.`だけを表示します。

## 記念日通知

`dating`テーブルで通知日、通知文面、投稿先チャンネルIDを管理します。マイグレーションには本番値を含めません。通知設定には次の列を使用します。

| 列 | 用途 |
| --- | --- |
| `target_date` | 毎年通知する`MMdd`、または100日単位で通知する`yyyyMMdd` |
| `message` | 通知文面。`yyyyMMdd`の場合は`%s`へ経過日数を挿入 |
| `channel_id` | 投稿先のSlackチャンネルID |

`POST /api/dating/notify`は、日本時間の当日を基準に該当データを判定します。8桁の`yyyyMMdd`が未来の日付の場合は通知対象外です。同じチャンネルに複数の該当データがある場合は、文面を改行でまとめて1件投稿します。該当データがない場合は投稿しません。処理はリクエスト内で同期実行し、正常終了時はHTTP 204を返します。

## API認証

認証対象ルートと使用するBearer Tokenは次のとおりです。

| ルート | 環境変数 |
| --- | --- |
| `POST /api/mail/process` | `SCHEDULER_BEARER_TOKEN` |
| `POST /api/dating/notify` | `SCHEDULER_BEARER_TOKEN` |
| `POST /api/overtime/notify` | `OVERTIME_BEARER_TOKEN` |

各ルートの実装時に`BearerTokenMiddleware`をルートミドルウェアとして登録し、`Authorization: Bearer <token>`ヘッダーを検証します。ヘッダーが未指定、形式不正、またはトークンが一致しない場合は、トークン値をレスポンスへ含めずHTTP 401を返します。

## 残業通知

`overtime_notification_settings`テーブルの`id = 1`で、通知文面と投稿先SlackチャンネルIDを管理します。マイグレーションには本番値を含めません。

| 列 | 用途 |
| --- | --- |
| `message` | 通知文面 |
| `channel_id` | 投稿先のSlackチャンネルID |

HTTP Shortcutsから`POST /api/overtime/notify`を呼び出し、`Authorization: Bearer <OVERTIME_BEARER_TOKEN>`ヘッダーを設定します。リクエスト本文は使用せず、通知文面を外部から指定できません。

旧HomeServerのIncoming Webhookは再利用しません。旧WebhookをSlackの管理画面で失効させ、現在のSlack Appと`SLACK_BOT_TOKEN`を使用します。

成功時はHTTP 200で次のJSONを返します。

```json
{
  "message": "Overtime notification sent.",
  "timestamp": "1234567890.123456"
}
```

設定がない場合はHTTP 500、Slack投稿に失敗した場合はHTTP 502で、秘密値を含まないJSONエラーを返します。

## メール検索

`mail_api`テーブルの`enable_flag = 1`であるルールを取得し、`target_from`をINBOX内の送信者または件名へ、大文字小文字を区別せず部分一致させます。検索結果としてメッセージのUIDとINBOXのUIDVALIDITYだけを返し、送信者、件名、本文はログや検索結果へ含めません。

ローカルのappコンテナにはPECL IMAP 1.0.3を導入します。IMAP over SSL/TLSのポート993を使用します。手動疎通確認ではINBOXを読み取り専用で参照し、メール処理APIではSlack投稿が成功するまで既読化しません。

ローカルから実IMAPへの疎通を確認する場合は、Git管理対象外の`.env`へIMAP用の4つの環境変数を設定し、Docker経由で次を実行します。

```shell
docker compose run --rm --no-deps app php bin/check-imap.php
```

このコマンドによる実IMAP接続は読み取り専用であり、メールの既読化、移動、削除を行いません。
接続に失敗した場合は、パスワード、ユーザー名、メールアドレス、ホスト名などの設定値を除去した診断情報を標準エラーへ表示します。診断情報を取得できない場合は、`IMAP connection failed.`だけを表示します。

本番接続を確認する場合は、Git管理対象外の`.env`または実行環境へIMAP用の4つの環境変数だけを設定し、XServerのPHP 8.5.5を明示して次を実行します。

```shell
/opt/php-8.5.5/bin/php bin/check-imap.php
```

成功時は`IMAP connectivity check succeeded.`だけを出力します。接続、検索、フォルダ参照に失敗した場合は、メールサーバー、アカウント、フォルダ、メールの実値を含めず、それぞれ異なるエラーで終了コード1を返します。この確認はメールの移動、削除、既読化を行いません。

メール件名はMIMEエンコードと文字コードを処理してUTF-8へ変換します。本文はmultipartを再帰的に探索し、quoted-printableまたはbase64をデコードした`text/plain`を優先して取得します。ファイル名または添付指定があるパートは本文として扱いません。HTMLしかないメールは本文を空文字とし、取得する本文はSlack投稿で推奨される長さに合わせて先頭4,000文字へ制限します。

## メール処理履歴

メール処理履歴は、個人情報を含まないメールボックス識別子、UIDVALIDITY、UIDの組み合わせで一意に管理します。Slack投稿済みと処理完了を別に記録し、Slack投稿後にメール移動だけ失敗した場合は、保存済みのSlackのtimestampを使って再投稿せず移動だけを再試行できる状態にします。件名、本文、送信者、メールアドレスは保存しません。

完了済み履歴は完了から90日を過ぎたものだけを削除対象とします。Slack投稿済み・移動未完了の履歴は、二重投稿防止のため完了するまで自動削除しません。

## メール処理API

`mail_api`テーブルでは、有効なメール処理ルールを次の列で管理します。

| 列 | 用途 |
| --- | --- |
| `target_from` | 送信者または件名へ部分一致させる文字列 |
| `to_folder` | INBOXからの移動先フォルダ |
| `channel_id` | 投稿先のSlackチャンネルID |
| `enable_flag` | ルールの有効状態 |

`POST /api/mail/process`は、有効なルールを登録順に処理します。対象メールの件名と`text/plain`本文を整形してSlackへ投稿し、投稿成功後に既読化して指定フォルダへ移動し、INBOXから削除します。Slack投稿に失敗したメールは移動しません。Slack投稿後の移動に失敗した場合は、処理履歴に基づいて次回の実行時にSlackへ再投稿せず、既読化と移動だけを再試行します。

処理はHTTPリクエスト内で同期実行し、Bearer Tokenには`SCHEDULER_BEARER_TOKEN`を使用します。応答はHTTP 200のJSONで、全メールの処理結果と失敗件数を返します。

```json
{
  "success": true,
  "failure_count": 0
}
```

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

## 本番デプロイ

本番デプロイは手動で行います。GitHub Actionsは使用しません。理由は次のとおりです。

- 現時点でデプロイ用のGitHub SecretsとWorkflowが存在しません。
- 手動SSHで配備できる環境が既にあります。
- デプロイ頻度が高くない個人サービスです。
- GitHub Actions化には、鍵管理・失敗時対応・ログ設計など、このデプロイ手順を超える検討が追加で必要になります。

GitHub Actions化が必要になった場合は、別Issueとして扱います。

### 配置の考え方

- ドメインのドキュメントルート(`public_html`)は固定であり、変更できません。ここには公開してよいファイルだけを置きます。
- アプリ本体(`bootstrap`、`src`、`vendor`、`composer.*`、`database`、`bin`、`.env`など)は、アカウントホーム配下かつ`public_html`外の専用ディレクトリへ配置します。以下ではこのディレクトリを`<app-directory>`と表記します。実際の絶対パスはリポジトリへ記載しません。
- `public_html`には次の2つだけを配置します。
  - `index.php`: `<app-directory>/public/index.php`へのシンボリックリンク
  - `.htaccess`: `<app-directory>/public/.htaccess`をコピーした通常ファイル
- 実機で確認できたのは、`public_html`内のシンボリックリンク経由で`public_html`外のPHPファイルを実行した場合に、PHPの`__DIR__`が実体側のディレクトリで解決されることだけです。`.htaccess`をシンボリックリンクにした場合にApacheがRewriteや`Authorization`ヘッダー転送を同様に処理するかは確認していないため、`.htaccess`はシンボリックリンクにせず、`public_html`直下へ通常ファイルとして配置します。この方式(方式A)により、`public/index.php`はコード変更なしでそのまま利用できます。

### 初回デプロイ

1. SSHでログインし、`<app-directory>`を作成してリポジトリを取得します。

    ```shell
    mkdir -p <app-directory>
    cd <app-directory>
    git clone https://github.com/bvlion/BvlionBatch5.git .
    ```

2. 専用のComposerを、検証済みチェックサムでBvlionBatch5専用の非公開ツールディレクトリへ配置します。共有Composerは使用・更新しません。Composerの公式インストーラー検証手順に沿って、ダウンロード・SHA-384検証・インストール・後始末を1つのスクリプトで実行します。チェックサムが一致しない場合は、`composer-setup.php`を削除したうえで必ず非ゼロの終了コードで停止し、インストールへは進みません。

    ```shell
    mkdir -p <tools-directory>
    cd <tools-directory>

    EXPECTED_SIGNATURE="$(/opt/php-8.5.5/bin/php -r "echo file_get_contents('https://composer.github.io/installer.sig');")"
    /opt/php-8.5.5/bin/php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_SIGNATURE="$(/opt/php-8.5.5/bin/php -r "echo hash_file('sha384', 'composer-setup.php');")"

    if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]; then
        echo 'ERROR: Composer installer signature mismatch.' >&2
        rm -f composer-setup.php
        exit 1
    fi

    /opt/php-8.5.5/bin/php composer-setup.php --install-dir=<tools-directory> --filename=composer.phar
    RESULT=$?
    rm -f composer-setup.php
    unset EXPECTED_SIGNATURE ACTUAL_SIGNATURE
    exit $RESULT
    ```

    署名不一致時は`ERROR: Composer installer signature mismatch.`を表示して終了コード1で停止し、`composer-setup.php`は削除されます。この場合は`composer.phar`をインストールせず、原因を確認してから再実行してください。

3. 本番用の依存関係をインストールします。開発用パッケージを含めず、`composer.lock`の内容どおりに導入します。

    ```shell
    cd <app-directory>
    /opt/php-8.5.5/bin/php <tools-directory>/composer.phar install --no-dev --optimize-autoloader --classmap-authoritative
    ```

    `--optimize-autoloader --classmap-authoritative`は、デプロイのたびに`composer install`を実行する運用と整合するため採用します。コードの変更後に依存関係を更新し忘れると、追加したクラスが読み込めなくなる点に注意してください。

4. `.env`を配置します。値は`.env.example`をコピーし、README「環境設定」節の一覧に従って本番値を設定します。実際の値はコミットしません。

    ```shell
    cp .env.example .env
    chmod 600 .env
    ```

5. `public_html`側に、公開してよい2ファイルだけを配置します。`index.php`はシンボリックリンク、`.htaccess`は通常ファイルとしてコピーします。`public_html`内に他のファイルは置きません。

    ```shell
    ln -s <app-directory>/public/index.php <public_html-directory>/index.php
    cp <app-directory>/public/.htaccess <public_html-directory>/.htaccess
    ```

6. 権限を設定します。一般的な作りに寄せるなら、ディレクトリは755、ファイルは644、`.env`は600を目安にします。この権限で本番のHTTP実行環境から読み取れるかは、後述の「疎通確認」で確認してください。想定と異なるエラーが出た場合は、実際の実行ユーザー・グループに合わせて調整が必要です。具体的な必要権限は`docs/production-environment.md`に記載がなく、本手順書だけでは確定できません。

7. マイグレーションを適用します(「データベースとマイグレーション」節を参照)。

    ```shell
    /opt/php-8.5.5/bin/php bin/migrate.php
    ```

8. 「/healthに依存しない疎通確認」を実施します。

### 更新デプロイ

```shell
cd <app-directory>
git fetch origin main
git checkout main
git pull --ff-only origin main
/opt/php-8.5.5/bin/php <tools-directory>/composer.phar install --no-dev --optimize-autoloader --classmap-authoritative
/opt/php-8.5.5/bin/php bin/migrate.php
cp <app-directory>/public/.htaccess <public_html-directory>/.htaccess
```

`public_html`側の`index.php`は初回作成時のシンボリックリンクを再利用するため、更新デプロイのたびに作り直す必要はありません。一方`.htaccess`は通常ファイルとしてコピーしているため、リポジトリ側の`public/.htaccess`が変更されていなくても、更新デプロイのたびに上書きコピーしてください。更新後は「疎通確認」を実施します。

### 失敗時にどこまで戻すか

- **アプリコード**: `git log`で直前の安定コミットを確認し、`git checkout <直前のコミット>`で戻します。その後、そのコミット時点の`composer.lock`に合わせて`composer install`を再実行します。
- **マイグレーション**: `bin/migrate.php`にロールバック機能はありません。マイグレーション適用後に問題が起きた場合は、データベースのバックアップからの復元、または追加のマイグレーションでの是正を検討し、適用済みのマイグレーションファイルは変更しません。
- **公開ファイル**: `public_html`側は`index.php`のシンボリックリンクと`.htaccess`の通常ファイルの2つだけのため、問題が起きた場合は`index.php`のリンクを削除する、または`.htaccess`を退避すれば公開を止められます。

### /healthに依存しない疎通確認

`/health`エンドポイントは実装しません。疎通確認は次の手順で行います。

#### 0. 外部サービス疎通の補助確認(任意・推奨)

API疎通確認の前に、Slack・IMAPそれぞれの外部サービスへの到達性を単体で確認しておくと、問題が起きた際の切り分けに役立ちます。

```shell
/opt/php-8.5.5/bin/php bin/check-slack.php
/opt/php-8.5.5/bin/php bin/check-imap.php
```

それぞれ専用の環境変数だけを使用し、既存のAPIやデータベースには触れません(詳細は「Slack App設定」「メール検索」節を参照)。

#### 1. 未認証確認

3つのAPIすべてが、Authorizationヘッダーなしで401を返すことを確認します。これで確認できるのは、ルーティングとBearer Token認証による拒否が機能していることです。`Authorization`ヘッダーが`.htaccess`のRewriteによってPHPまで到達しているかどうかは、この時点では確認できません(ヘッダーがまったく転送されていなくても、未指定の場合と同じ401になるためです)。ヘッダー転送の確認は、後述の「認証済み確認」で正しいBearer Tokenを使ったリクエストが成功することによって行います。

```shell
curl -i -X POST https://<domain>/api/mail/process
curl -i -X POST https://<domain>/api/dating/notify
curl -i -X POST https://<domain>/api/overtime/notify
```

3件とも`HTTP/1.1 401`を確認します。

#### 2. 認証済み確認

副作用(実際のSlack投稿・メールの既読化や移動)を発生させない状態を用意したうえで、正規のBearer Tokenを付与して確認します。正しいBearer Tokenを付与したリクエストが期待どおりの応答を返すことにより、`Authorization`ヘッダーが`.htaccess`のRewriteを経由してPHPまで到達していることも合わせて確認できます。

- **記念日通知**: `dating`テーブルに該当データがない状態(初回デプロイ直後など)で実行し、投稿が発生せずHTTP 204が返ることを確認します。

    ```shell
    curl -i -X POST https://<domain>/api/dating/notify \
      -H "Authorization: Bearer <SCHEDULER_BEARER_TOKEN>"
    ```

- **メール処理**: `mail_api`テーブルに`enable_flag = 1`の行がない状態で実行します。有効なルールが1件もない場合、IMAPへ接続せずに即座に応答するため、IMAP接続やSlack投稿を発生させずに確認できます。

    ```shell
    curl -i -X POST https://<domain>/api/mail/process \
      -H "Authorization: Bearer <SCHEDULER_BEARER_TOKEN>"
    ```

    `{"success":true,"failure_count":0}`とHTTP 200を確認します。

- **残業通知**: 本番用の設定(`overtime_notification_settings`の`id = 1`)をテスト値で一時的に上書きすることはしません。次の段階で確認します。

    - 設定が未登録の状態(初回デプロイ直後など)で実行すると、Slack投稿を発生させずにHTTP 500(`Overtime notification configuration is missing.`)が返ります。これによりBearer認証、ルーティング、データベース接続までを確認できます。

        ```shell
        curl -i -X POST https://<domain>/api/overtime/notify \
          -H "Authorization: Bearer <OVERTIME_BEARER_TOKEN>"
        ```

    - Slackへの接続と投稿そのものは、`bin/check-slack.php`で確認します。このコマンドはテスト用チャンネル(`SLACK_TEST_CHANNEL_ID`)へテストメッセージを1件投稿します(詳細は「Slack App設定」節を参照)。

        ```shell
        /opt/php-8.5.5/bin/php bin/check-slack.php
        ```

    - `POST /api/overtime/notify`をSlack投稿まで含めて確認するのは、本番用の文面・チャンネルIDを`overtime_notification_settings`へ正式に登録した後に行ってください。本番設定をテスト値で上書きする確認方法は採用しません。
    - 本番設定を登録した後にこのAPIを実行すると、設定済みの本番チャンネルへ実際の通知が1件投稿されます。**実行前に必ず承認を得てから行ってください。**

## 開発運用

- 1つのIssueにつき、1つのブランチと1つのPRを作成します。
- PRはユーザーの明示的な承認を得るまでマージしません。
- Issueに記載された範囲を最小差分で実装し、明示されていない横展開は行いません。

## Publicリポジトリの運用

- 本番値、個人情報、秘密情報をコミットしません。
- Issue、PR、テスト、fixture、ログ、SQLにも実データを含めません。
- 認証情報、Webhook、チャンネルID、メール内容、通知文面、データベースの本番値は、リポジトリ外の安全な場所で管理します。
- サンプルやテストには、実データから生成していない架空の値を使用します。
