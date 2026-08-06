# 旧環境データの移行(Issue #15)

旧BvlionBatch4(`dating`・`mail_api`)と旧HomeServer(残業通知)のデータをBvlionBatch5の本番DBへ移行する手順です。実データ・認証情報・実際のチャンネル名やIDは、このリポジトリのいかなるファイルにも含めません。

以下の手順はすべて貴方(リポジトリ運用者)が実行してください。このリポジトリのツールは実行主体になりません。実行環境は工程によって異なります。

- **エクスポート**(`bin/export-legacy-data.php`)：旧DBへ接続でき、PHP 8.5と`pdo_mysql`拡張が利用できる環境で実行します。XServerから旧DBへ接続できるかどうかは本ドキュメント作成時点では未確認のため、実際にどの環境で実行するかは断定しません。本番マイグレーション適用後、実施時に確認してください。
- **インポート・検証・本番マイグレーション**(`bin/import-legacy-data.php`・`bin/verify-legacy-migration.php`・`bin/migrate.php`)：本番DBへ書き込む工程のため、XServerのPHP 8.5.5で実行します。

## 0. 前提

- 移行対象は `dating`・`mail_api`・`overtime_notification_settings` の3テーブルです。
- `mail_api.channel_id` は本Issueのマイグレーションで `NULL` を許容します。旧環境でSlack投稿がスキップされていた行は、`channel_id = NULL` として移行し、新環境でも同様にSlack投稿だけをスキップします。
- 旧`dating`・旧`mail_api`の主キー(`pk`)は、新環境の`id`へそのまま引き継ぎます。
- 2節のスキーマ・集計確認は実施済みです。確認済みの件数は次のとおりです。

  | 項目 | 件数 |
  | --- | --- |
  | `dating` 総件数 | 4 |
  | `mail_api` 総件数 | 44 |
  | `mail_api.enable_flag = 1`(有効) | 43 |
  | `mail_api.enable_flag = 0`(無効) | 1 |
  | 移行後に`channel_id = NULL`となる`mail_api`行 | 31 |
  | 残業通知設定(新規登録) | 1 |

  `bin/import-legacy-data.php`・`bin/verify-legacy-migration.php`はこれらの件数を既定値として検証し、入力ファイルの件数がこれと一致しない場合は`valid: false`としてDBへ書き込みません。

## 1. 作業ディレクトリの準備

リポジトリ外・`public_html`外・Webから公開されない場所に作業ディレクトリを用意します。以降、この場所を `<migration-work-directory>` と表記します。

```shell
mkdir -m 700 <migration-work-directory>
```

このディレクトリの直下に作成するファイルはすべて権限600とし、移行完了後に削除します。

## 2. 旧DBのスキーマ・集計確認(参考)

旧DBで次のSQLを実行し、スキーマと集計値(件数のみ)を確認します。実データは出力しません。

```sql
SHOW CREATE TABLE dating;
SHOW CREATE TABLE mail_api;
```

```sql
SELECT
  COUNT(*) AS total_count,
  SUM(pk IS NULL) AS pk_null_count,
  SUM(pk <= 0) AS pk_non_positive_count,
  SUM(target_date IS NULL) AS target_date_null_count,
  SUM(target_date = '') AS target_date_empty_count,
  SUM(message IS NULL) AS message_null_count,
  SUM(message = '') AS message_empty_count
FROM dating;
```

```sql
SELECT
  COUNT(*) AS total_count,
  SUM(enable_flag = 1) AS enable_flag_1_count,
  SUM(enable_flag = 0) AS enable_flag_0_count,
  SUM(enable_flag IS NULL) AS enable_flag_null_count,
  SUM(enable_flag IS NOT NULL AND enable_flag NOT IN (0, 1)) AS enable_flag_other_count,
  SUM(pk IS NULL) AS pk_null_count,
  SUM(pk <= 0) AS pk_non_positive_count,
  SUM(target_from IS NULL) AS target_from_null_count,
  SUM(target_from = '') AS target_from_empty_count,
  SUM(to_folder IS NULL) AS to_folder_null_count,
  SUM(to_folder = '') AS to_folder_empty_count,
  SUM(channel IS NULL) AS channel_null_count,
  SUM(channel = '') AS channel_empty_count,
  SUM(user_name IS NULL) AS user_name_null_count,
  SUM(user_name = '') AS user_name_empty_count,
  SUM(icon_url IS NULL) AS icon_url_null_count,
  SUM(icon_url = '') AS icon_url_empty_count,
  SUM(prefix_format IS NULL) AS prefix_format_null_count,
  SUM(prefix_format = '') AS prefix_format_empty_count,
  SUM(
    channel IS NULL OR user_name IS NULL OR icon_url IS NULL OR prefix_format IS NULL
  ) AS slack_skip_condition_all_rows_count,
  SUM(
    enable_flag = 1
    AND (channel IS NULL OR user_name IS NULL OR icon_url IS NULL OR prefix_format IS NULL)
  ) AS slack_skip_condition_enabled_rows_count
FROM mail_api;
```

## 3. 旧DBからのエクスポート

`<migration-work-directory>/legacy-db.env` を作成します(`docs/legacy-db.env.example` を参考にしてください。値は本番の旧DB接続情報です)。

```shell
chmod 600 <migration-work-directory>/legacy-db.env
```

`<php-binary>`は、旧DBへ接続できPHP 8.5・`pdo_mysql`が利用できる環境のPHP実行ファイルです(XServerとは限りません。実施時に確認してください)。

```shell
<php-binary> bin/export-legacy-data.php \
  --env-file=<migration-work-directory>/legacy-db.env \
  --table=dating \
  --output=<migration-work-directory>/dating.json

<php-binary> bin/export-legacy-data.php \
  --env-file=<migration-work-directory>/legacy-db.env \
  --table=mail_api \
  --output=<migration-work-directory>/mail_api.json
```

DB接続情報は`--env-file`で指定したファイルからのみ読み込みます。プロセス環境変数に同名の変数が存在していても使用しません。指定ファイルが存在しない・読み取れない・必須項目が欠けている場合は、値を含まないエラーメッセージで終了します。

- 出力ファイルは一時ファイルへ書き込んだ後、成功時のみ完成ファイル名へ`rename`されます。権限は作成時から600です。
- 完成ファイル・一時ファイルのいずれかが既に存在する場合はエラー終了し、上書きしません。
- 標準出力には行数と出力先パスのみ表示し、実データは表示しません。

## 4. `migration-settings.json` と `channel_map.json` の作成

いずれも `<migration-work-directory>` に、貴方が手動で作成します(`chmod 600`)。

`migration-settings.json`:

```json
{
  "dating_channel": "<旧Slackチャンネル名>",
  "overtime_message": "<残業通知の文面>",
  "overtime_channel": "<旧Slackチャンネル名>"
}
```

- `dating_channel` は旧`CheckDating`が固定で使用していたチャンネル名です。
- `overtime_message` / `overtime_channel` は旧HomeServerに設定テーブルがないため、新規に決めた文面・チャンネル名を記載します。

`channel_map.json`:

```json
{
  "<旧Slackチャンネル名>": "<新SlackチャンネルID>"
}
```

`mail_api.json` 内の `channel` 値、および `migration-settings.json` の `dating_channel`・`overtime_channel` を解決するために必要な、すべての旧チャンネル名を含めてください。ただし、`mail_api.json`の各行のうち `channel`・`user_name`・`icon_url`・`prefix_format` のいずれかが `null` の行は、`channel` の値が対応表になくてもエラーになりません(その行は`channel_id = NULL`として移行されるため、変換を行わないからです)。

## 5. dry-run

```shell
/opt/php-8.5.5/bin/php bin/import-legacy-data.php \
  --dating=<migration-work-directory>/dating.json \
  --mail-api=<migration-work-directory>/mail_api.json \
  --settings=<migration-work-directory>/migration-settings.json \
  --channel-map=<migration-work-directory>/channel_map.json \
  --dry-run
```

`--dry-run`でもDBへ接続し、`dating`・`mail_api`・`overtime_notification_settings`の既存件数を確認します(書き込みは行いません)。次を確認してください。

- `valid: true`
- `expected_counts.*`の`matches`がすべて`true`(0節の確認済み件数と入力件数が一致していることを表します)
- `all_tables_empty: true` / `can_execute: true`

`valid: false`の場合は `error:` 行を確認し、4節の方針に従って入力ファイルを修正してください。`all_tables_empty: false`の場合は、対象テーブルに既存データが残っています。原因を確認してから進めてください。

## 6. バックアップ

本実行の前に、本番DBのバックアップを取得してください。バックアップ手段はXServerで実際に利用できる方法を確認したうえで選んでください(このドキュメントでは断定しません)。

## 7. 本実行

```shell
/opt/php-8.5.5/bin/php bin/import-legacy-data.php \
  --dating=<migration-work-directory>/dating.json \
  --mail-api=<migration-work-directory>/mail_api.json \
  --settings=<migration-work-directory>/migration-settings.json \
  --channel-map=<migration-work-directory>/channel_map.json
```

- `dating`・`mail_api`・`overtime_notification_settings` のいずれかに既存データがある場合、何も書き込まずに `abort_reason` を表示して終了します。
- 対象テーブルがすべて空の場合のみ、1トランザクション内で全件INSERTします。途中で失敗した場合は自動的にロールバックされ、`executed: false` になります。
- 成功後にこのコマンドを再実行すると、テーブルが空でなくなっているため、必ず中止されます(二重投入は起きません)。

## 8. 検証

```shell
/opt/php-8.5.5/bin/php bin/verify-legacy-migration.php \
  --dating=<migration-work-directory>/dating.json \
  --mail-api=<migration-work-directory>/mail_api.json \
  --settings=<migration-work-directory>/migration-settings.json \
  --channel-map=<migration-work-directory>/channel_map.json
```

次がすべて満たされていることを確認してください。実際の値は一切出力されません。

- `dating.mismatched_count` / `mail_api.mismatched_count` / `dating.order_mismatch_count` / `mail_api.order_mismatch_count` がいずれも `0`
- `dating.required_field_violation_count` / `mail_api.required_field_violation_count` がいずれも `0`
- `mail_api.expected_null_channel_id_count` と `mail_api.actual_null_channel_id_count` が一致(31)
- `mail_api.enabled_count_expected` と `enabled_count_actual` が一致(43)、`disabled_count_expected` と `disabled_count_actual` が一致(1)
- `overtime.matched: true`

## 9. 3機能の本番相当確認

READMEの各機能節(記念日通知・メール処理・残業通知)に沿って、本番相当の確認を行ってください。`channel_id`が`NULL`のメール処理ルールに一致するメールは、Slack投稿なしで既読化・移動されることも確認してください。残業通知はSlackへ実際に投稿されるため、実行前に必ず承認を得てから行ってください。

## 10. 後片付け

`<migration-work-directory>` 配下のファイル(エクスポート結果、`migration-settings.json`、`channel_map.json`、`legacy-db.env`)をすべて削除してください。

```shell
rm -f <migration-work-directory>/*.json <migration-work-directory>/legacy-db.env
```

## 失敗時の停止・復旧

- **本実行のトランザクション中の失敗**：自動的にロールバックされます。対象テーブルは実行前の状態(空)のまま残るため、入力ファイルを修正したうえで本実行をやり直せます。
- **本実行の成功後に問題が判明した場合**：`TRUNCATE`による即時のやり直しは案内しません。6節で取得したバックアップからの復元を基本とし、復元手段はXServerで実際に利用できる方法に従ってください。
- **3機能の本番相当確認で問題が判明した場合**：残業通知など実際にSlackへ投稿する確認は、問題が解消してから改めて承認のうえ実行してください。
