<?php
require_once __DIR__ . '/../config/mail_config.php';

/**
 * Keyless Gmail API sending via Domain-Wide Delegation.
 *
 * Flow (no private key file ever stored):
 *   1. Cloud Run's own service account fetches its own access token from
 *      the metadata server.
 *   2. That token is used to ask the IAM Credentials API to sign a JWT
 *      "as" GMAIL_SERVICE_ACCOUNT_EMAIL, with "sub" = the Workspace user
 *      we want to send as (requires the serviceAccountTokenCreator role,
 *      already granted on that service account).
 *   3. The signed JWT is exchanged at Google's OAuth token endpoint for an
 *      access token scoped to gmail.send, impersonating that user.
 *   4. That token calls users.messages.send — Gmail treats it exactly like
 *      the user sent it themselves, so a copy lands in their own Sent
 *      folder.
 */

function gmailMetadataAccessToken(): ?string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $url = 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Metadata-Flavor: Google'],
        CURLOPT_TIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        error_log('gmailMetadataAccessToken: metadata server unreachable (code ' . $code . ')');
        return null;
    }
    $data = json_decode((string)$body, true);
    $token = $data['access_token'] ?? null;
    if (!is_string($token) || $token === '') {
        return null;
    }
    $cached = $token;
    return $cached;
}

function gmailBase64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Impersonate $subjectEmail via the delegated service account and return an
 * access token scoped to gmail.send. Returns null on any failure.
 */
function gmailGetUserAccessToken(string $subjectEmail): ?string {
    // Cache per subject for the life of this process — the delegated token
    // is valid for an hour, so a request that sends as the same person more
    // than once (e.g. one email addressed to several recipients, or several
    // emails in the same request) reuses it instead of re-doing the signJwt
    // + OAuth token-exchange round trip every single time.
    static $cache = [];
    $key = strtolower($subjectEmail);
    $now = time();
    if (isset($cache[$key]) && $cache[$key]['expires'] > $now + 60) {
        return $cache[$key]['token'];
    }

    $callerToken = gmailMetadataAccessToken();
    if ($callerToken === null) {
        return null;
    }

    $claim = [
        'iss'   => GMAIL_SERVICE_ACCOUNT_EMAIL,
        'sub'   => $subjectEmail,
        'scope' => 'https://www.googleapis.com/auth/gmail.send',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $saResource = 'projects/-/serviceAccounts/' . rawurlencode(GMAIL_SERVICE_ACCOUNT_EMAIL);
    $signUrl = 'https://iamcredentials.googleapis.com/v1/' . $saResource . ':signJwt';
    $resp = ysmsHttpPostJson($signUrl, json_encode(['payload' => json_encode($claim)]), [
        'Authorization: Bearer ' . $callerToken,
        'Content-Type: application/json',
    ]);
    if (!$resp['ok']) {
        error_log('gmailGetUserAccessToken signJwt failed for ' . $subjectEmail . ': ' . $resp['body']);
        return null;
    }
    $signed = json_decode($resp['body'], true);
    $signedJwt = $signed['signedJwt'] ?? null;
    if (!is_string($signedJwt) || $signedJwt === '') {
        error_log('gmailGetUserAccessToken: no signedJwt in response for ' . $subjectEmail);
        return null;
    }

    $tokenResp = ysmsHttpPostJson('https://oauth2.googleapis.com/token', http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $signedJwt,
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    if (!$tokenResp['ok']) {
        error_log('gmailGetUserAccessToken token exchange failed for ' . $subjectEmail . ': ' . $tokenResp['body']);
        return null;
    }
    $tokenData = json_decode($tokenResp['body'], true);
    $accessToken = $tokenData['access_token'] ?? null;
    if (!is_string($accessToken) || $accessToken === '') {
        return null;
    }
    $expiresIn = is_numeric($tokenData['expires_in'] ?? null) ? (int)$tokenData['expires_in'] : 3600;
    $cache[$key] = ['token' => $accessToken, 'expires' => $now + $expiresIn];
    return $accessToken;
}

/**
 * Send a raw RFC 2822 MIME message as $subjectEmail via the Gmail API.
 * Returns true on success; sets $GLOBALS['ysms_last_mail_error'] on failure.
 */
function gmailApiSendRaw(string $subjectEmail, string $rawMime): bool {
    $domain = '@' . ltrim((string)GMAIL_API_WORKSPACE_DOMAIN, '@');
    if (stripos($subjectEmail, $domain) === false || !filter_var($subjectEmail, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['ysms_last_mail_error'] = 'Gmail API: refusing to impersonate address outside ' . GMAIL_API_WORKSPACE_DOMAIN . ': ' . $subjectEmail;
        return false;
    }

    $accessToken = gmailGetUserAccessToken($subjectEmail);
    if ($accessToken === null) {
        $GLOBALS['ysms_last_mail_error'] = 'Gmail API: could not obtain delegated access token for ' . $subjectEmail;
        return false;
    }

    $sendUrl = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';
    $payload = json_encode(['raw' => gmailBase64UrlEncode($rawMime)]);
    $resp = ysmsHttpPostJson($sendUrl, $payload, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    if (!$resp['ok']) {
        $GLOBALS['ysms_last_mail_error'] = 'Gmail API send failed: ' . $resp['body'];
        error_log($GLOBALS['ysms_last_mail_error']);
        return false;
    }
    return true;
}
