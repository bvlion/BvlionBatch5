import { test } from 'node:test';
import assert from 'node:assert/strict';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { mkdtemp, readFile, rm, stat } from 'node:fs/promises';
import { validateRuntimeEnv, writeRuntimeEnv } from '../scripts/prepare-runtime-env.js';

const sample = [
  'HOME_GOOGLE_ACCOUNT=allowed@example.invalid',
  'OPENAI_API_KEY=fictional-key',
  'INCOMING_WEBHOOK_URL=https://example.invalid/webhook',
  'HOME_LOCATION="架空の地域"',
  'SLACK_POST_SETTINGS=\'{"channel":"#fictional","username":"架空名"}\''
].join('\n') + '\n';

test('dotenvの引用符とSlack JSONを復元する', () => {
  const parsed = validateRuntimeEnv(sample);
  assert.equal(parsed.HOME_LOCATION, '架空の地域');
  assert.deepEqual(JSON.parse(parsed.SLACK_POST_SETTINGS), { channel: '#fictional', username: '架空名' });
});
test('一時ファイルを権限600で作り、renameする', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'glance-env-'));
  try {
    const file = join(dir, '.env.b-glance');
    await writeRuntimeEnv(sample, file);
    assert.equal(await readFile(file, 'utf8'), sample);
    assert.equal((await stat(file)).mode & 0o777, 0o600);
    await assert.rejects(stat(file + '.tmp'), { code: 'ENOENT' });
  } finally { await rm(dir, { recursive: true, force: true }); }
});
test('未設定・不正JSON・余計な設定・必須値不足を秘密を露出せず拒否する', () => {
  const invalid = ['', sample.replace('OPENAI_API_KEY=fictional-key','OPENAI_API_KEY='), sample + 'EXTRA_KEY=private\n',
    sample.replace('SLACK_POST_SETTINGS=\'{"channel":"#fictional","username":"架空名"}\'', "SLACK_POST_SETTINGS='{private'")];
  for (const source of invalid) {
    assert.throws(() => validateRuntimeEnv(source), error =>
      !error.message.includes('fictional-key') && !error.message.includes('private'));
  }
});
