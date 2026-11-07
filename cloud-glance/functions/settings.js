function getAllowedGoogleAccounts() { return process.env.HOME_GOOGLE_ACCOUNT || ''; }
function getOpenaiApiKey() { return process.env.OPENAI_API_KEY || ''; }
function getIncomingWebhookUrl() { return process.env.INCOMING_WEBHOOK_URL || ''; }
function getHomeLocation() { return process.env.HOME_LOCATION || ''; }
function getSlackPostSettings() {
  try { return JSON.parse(process.env.SLACK_POST_SETTINGS); }
  catch { throw new Error('Slack共有の設定を確認してください。', { cause: { isPublic: true } }); }
}
module.exports = { getAllowedGoogleAccounts, getOpenaiApiKey, getIncomingWebhookUrl, getHomeLocation, getSlackPostSettings };
