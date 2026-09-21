<?php
/**
 * Symmetric encryption for the incoming-mailbox credential (app password /
 * OAuth refresh token) stored in email_accounts.imap_secret_enc. Never
 * stored, logged, or echoed in plaintext anywhere.
 *
 * Key source: env var YSMS_EMAIL_ENC_KEY (set it in Cloud Run's env vars,
 * same place as YSMS_SMTP_PASSWORD). Without it, a fallback key derived
 * from DB_NAME is used so the feature still works on a fresh checkout, but
 * a loud error_log warns every time — set the real env var before
 * production use.
 */

function ysmsEmailEncryptionKey(): string {
    $key = getenv('YSMS_EMAIL_ENC_KEY') ?: '';
    if ($key !== '') {
        return hash('sha256', $key, true);
    }
    error_log('YSMS_EMAIL_ENC_KEY is not set — email account credentials are encrypted with a weak fallback key. Set YSMS_EMAIL_ENC_KEY in the environment before storing real mailbox credentials.');
    return hash('sha256', 'ysms-email-fallback-key-' . (defined('DB_NAME') ? DB_NAME : 'ysms'), true);
}

/** Returns base64(iv . tag . ciphertext), or '' for an empty input. */
function ysmsEmailEncrypt(string $plaintext): string {
    if ($plaintext === '') {
        return '';
    }
    $key = ysmsEmailEncryptionKey();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        error_log('ysmsEmailEncrypt: openssl_encrypt failed.');
        return '';
    }
    return base64_encode($iv . $tag . $cipher);
}

/** Reverses ysmsEmailEncrypt(); returns '' if decryption fails or input is empty. */
function ysmsEmailDecrypt(string $encoded): string {
    if ($encoded === '') {
        return '';
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 13) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = ysmsEmailEncryptionKey();
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        error_log('ysmsEmailDecrypt: openssl_decrypt failed (wrong key or corrupted value).');
        return '';
    }
    return $plain;
}
