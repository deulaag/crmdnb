<?php
/** Utilitários de perfil e sentimento exibidos na identidade do CRM. */

function humorEmoji(?string $humor): string
{
    return match ((string) $humor) {
        'tristeza' => '😢',
        'alegria' => '😄',
        'loucura' => '🤪',
        default => '',
    };
}

function perfilTabelasHumorDisponiveis(PDO $pdo): bool
{
    static $disponivel = null;
    if ($disponivel !== null) return $disponivel;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_profiles'");
        return $disponivel = ((int) $stmt->fetchColumn() === 1);
    } catch (Throwable) {
        return $disponivel = false;
    }
}

function humorUsuario(PDO $pdo, int $usuarioId): string
{
    if ($usuarioId <= 0 || !perfilTabelasHumorDisponiveis($pdo)) return '';
    static $cache = [];
    if (array_key_exists($usuarioId, $cache)) return $cache[$usuarioId];
    $stmt = $pdo->prepare('SELECT mood FROM user_profiles WHERE user_id=? LIMIT 1');
    $stmt->execute([$usuarioId]);
    return $cache[$usuarioId] = humorEmoji((string) ($stmt->fetchColumn() ?: ''));
}

function nomeUsuarioComHumor(PDO $pdo, int $usuarioId, string $nome): string
{
    $emoji = humorUsuario($pdo, $usuarioId);
    return trim($nome . ($emoji !== '' ? ' ' . $emoji : ''));
}
