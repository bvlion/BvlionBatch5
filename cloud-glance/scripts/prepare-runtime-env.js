import { parseEnv } from 'node:util';
import { writeFile, rename, rm } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// 本番値・不正入力の内容は例外やログに表示しない。
function configError() { return new Error('本番用の環境変数の設定項目・形式に誤りがあります。'); }
const keys = ['HOME_GOOGLE_ACCOUNT', 'OPENAI_API_KEY', 'INCOMING_WEBHOOK_URL', 'HOME_LOCATION', 'SLACK_POST_SETTINGS'];
export function validateRuntimeEnv(text) {
  if (typeof text !== 'string' || !text.trim()) throw configError();
  let values;
  try { values = parseEnv(text); } catch { throw configError(); }
  if (Object.keys(values).length !== keys.length || keys.some(key => !Object.hasOwn(values, key))) throw configError();
  if (!values.HOME_GOOGLE_ACCOUNT.trim() || !values.OPENAI_API_KEY.trim() ||
      !/^https:\/\//i.test(values.INCOMING_WEBHOOK_URL)) throw configError();
  let settings;
  try { settings = JSON.parse(values.SLACK_POST_SETTINGS); } catch { throw configError(); }
  if (!settings || typeof settings !== 'object' || Array.isArray(settings) ||
      Object.keys(settings).some(key => !['channel', 'username', 'icon_url'].includes(key) || typeof settings[key] !== 'string')) throw configError();
  return values;
}
export async function writeRuntimeEnv(text, target) {
  validateRuntimeEnv(text);
  const temp = target + '.tmp';
  try {
    await writeFile(temp, text.endsWith('\n') ? text : text + '\n', { flag: 'wx', mode: 0o600 });
    await rename(temp, target);
  } finally { await rm(temp, { force: true }); }
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    await writeRuntimeEnv(process.env.CLOUD_GLANCE_RUNTIME_ENV,
      resolve(dirname(fileURLToPath(import.meta.url)), '../functions/.env.b-glance'));
  } catch {
    process.stderr.write('cloud-glanceの本番用環境変数を準備できませんでした。\n');
    process.exitCode = 1;
  }
}
