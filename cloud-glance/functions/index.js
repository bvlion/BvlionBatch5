const { initializeApp } = require('firebase-admin/app');
const { onRequest } = require('firebase-functions/v2/https');

initializeApp();
const { app } = require('./app');

exports.cloudGlance = onRequest({ region: 'asia-east1', timeoutSeconds: 60 }, app);
