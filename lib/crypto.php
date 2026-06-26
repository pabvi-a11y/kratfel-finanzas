<?php
declare(strict_types=1);

/**
 * Cifrado simétrico AES-256-GCM para los tokens de QBO en reposo.
 * La clave viene de APP_ENCRYPTION_KEY (base64 de 32 bytes), nunca del repo.
 */

function crypto_key(): string {
    $b64 = APP_ENCRYPTION_KEY;
    if ($b64 === '') {
        throw new RuntimeException('APP_ENCRYPTION_KEY no configurada.');
    }
    $key = base64_decode($b64, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('APP_ENCRYPTION_KEY debe ser base64 de 32 bytes.');
    }
    return $key;
}

/** Devuelve binario: nonce(12) . tag(16) . ciphertext  */
function crypto_encrypt(string $plaintext): string {
    $key = crypto_key();
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('Fallo al cifrar.');
    return $nonce . $tag . $ct;
}

function crypto_decrypt(string $blob): string {
    $key = crypto_key();
    if (strlen($blob) < 28) throw new RuntimeException('Blob cifrado inválido.');
    $nonce = substr($blob, 0, 12);
    $tag = substr($blob, 12, 16);
    $ct = substr($blob, 28);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($pt === false) throw new RuntimeException('Fallo al descifrar (clave o datos inválidos).');
    return $pt;
}
